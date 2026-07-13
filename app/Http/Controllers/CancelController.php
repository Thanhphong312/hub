<?php

namespace App\Http\Controllers;

use App\Models\CancelPolicy;
use App\Models\CancelRequest;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Seller order-cancellation flow.
 *   preview        — seller sees refund policy + amount before requesting
 *   requestCancel  — seller raises a request; order → pending_cancel
 *   approve/reject — admin decides; approve refunds wallet + cancels order,
 *                    reject restores the previous status
 *   index          — list requests (seller: own, admin: all)
 */
class CancelController extends Controller
{
    // Statuses a cancel can be requested from are governed by the policy table;
    // these three can never be a source state regardless of policy.
    private const NON_CANCELLABLE = [
        Order::STATUS_SHIPPED,
        Order::STATUS_CANCELLED,
        Order::STATUS_PENDING_CANCEL,
    ];

    /** Refund = enabled policy% of paid_cost (unpaid → 0). */
    private function computeRefund(Order $order): array
    {
        $policy  = CancelPolicy::forStatus((int) $order->status);
        $enabled = $policy?->enabled ?? false;
        $percent = $enabled ? (float) $policy->percent : 0.0;
        $refund  = round($percent / 100 * (float) $order->paid_cost, 2);

        return [
            'enabled'       => $enabled,
            'percent'       => $percent,
            'paid_cost'     => (float) $order->paid_cost,
            'refund_amount' => $refund,
        ];
    }

    private function assertOwner(Request $request, Order $order): void
    {
        $user = $request->user();
        if ($user->hasRole('seller') && $order->user_id !== $user->id) {
            abort(403, 'Forbidden');
        }
    }

    /** POST /orders/{order}/cancel-preview */
    public function preview(Request $request, Order $order)
    {
        $this->assertOwner($request, $order);

        $calc = $this->computeRefund($order);
        $eligible = $calc['enabled'] && !in_array((int) $order->status, self::NON_CANCELLABLE, true);

        return response()->json(array_merge($calc, [
            'eligible'       => $eligible,
            'current_status' => (int) $order->status,
            'status_label'   => Order::STATUS_MAP[(int) $order->status] ?? 'unknown',
        ]));
    }

    /** POST /orders/{order}/request-cancel */
    public function requestCancel(Request $request, Order $order)
    {
        $this->assertOwner($request, $order);

        $data = $request->validate([
            'reason_cancel' => 'nullable|string|max:1000',
        ]);

        if (in_array((int) $order->status, self::NON_CANCELLABLE, true)) {
            return response()->json(['message' => 'This order cannot be cancelled from its current status'], 422);
        }

        $calc = $this->computeRefund($order);
        if (!$calc['enabled']) {
            return response()->json(['message' => 'Cancellation is not allowed for this order status'], 422);
        }

        $cancel = DB::transaction(function () use ($order, $request, $data) {
            $cancel = CancelRequest::create([
                'order_id'        => $order->id,
                'user_id'         => $order->user_id,
                'status'          => CancelRequest::STATUS_PENDING,
                'reason_cancel'   => $data['reason_cancel'] ?? null,
                'previous_status' => (int) $order->status,
            ]);

            $order->update(['status' => Order::STATUS_PENDING_CANCEL]);

            return $cancel;
        });

        // Best-effort Telegram ping to the admin cancel group. Never let a
        // notification failure affect the already-committed request.
        try {
            app(TelegramService::class)->notifyCancelRequest(
                $order->fresh()->load('latestCancelRequest'),
                $request->user(),
                (float) $calc['percent'],
                (float) $calc['refund_amount'],
            );
        } catch (\Throwable $e) {
            \Log::warning('[telegram] notifyCancelRequest: ' . $e->getMessage());
        }

        return response()->json([
            'message'        => 'Cancel request submitted',
            'cancel_request' => $cancel,
            'order'          => $order->fresh()->load('latestCancelRequest.user:id,name'),
        ]);
    }

    /** POST /orders/{order}/cancel/approve (admin) */
    public function approve(Request $request, Order $order)
    {
        $cancel = $order->cancelRequests()
            ->where('status', CancelRequest::STATUS_PENDING)
            ->latest()
            ->first();

        if (!$cancel) {
            return response()->json(['message' => 'No pending cancel request for this order'], 404);
        }

        $result = DB::transaction(function () use ($order, $cancel, $request) {
            // Recompute the refund at approval time from the current policy.
            $policy  = CancelPolicy::forStatus((int) $cancel->previous_status);
            $percent = ($policy?->enabled ? (float) $policy->percent : 0.0);
            $refund  = round($percent / 100 * (float) $order->paid_cost, 2);

            $user = User::whereKey($order->user_id)->lockForUpdate()->first();
            $txId = null;

            if ($refund > 0 && $user) {
                $balanceBefore = (float) $user->wallet;
                $user->increment('wallet', $refund);
                $order->decrement('paid_cost', min($refund, (float) $order->paid_cost));

                $tx = WalletTransaction::create([
                    'user_id'         => $user->id,
                    'order_id'        => $order->id,
                    'order_system_id' => $order->system_id,
                    'type'            => 'refund',
                    'status'          => WalletTransaction::STATUS_APPROVED,
                    'amount'          => $refund,
                    'balance_before'  => $balanceBefore,
                    'balance_after'   => $user->fresh()->wallet,
                    'note'            => "Cancel refund order #{$order->system_id}",
                ]);
                $txId = $tx->id;
            }

            // Cancel refunds label-paper stock if the order had consumed it.
            $order->fresh()->restockForUnship();
            $order->update(['status' => Order::STATUS_CANCELLED]);

            $cancel->update([
                'status'                => CancelRequest::STATUS_APPROVED,
                'refund_percent'        => $percent,
                'refund_amount'         => $refund,
                'wallet_transaction_id' => $txId,
                'reviewed_by'           => $request->user()->id,
                'reviewed_at'           => now(),
            ]);

            return ['refund' => $refund, 'wallet' => $user?->fresh()->wallet];
        });

        return response()->json([
            'message'        => 'Cancel approved',
            'refund_amount'  => $result['refund'],
            'wallet'         => $result['wallet'],
            'cancel_request' => $cancel->fresh(),
            'order'          => $order->fresh()->load('latestCancelRequest.user:id,name'),
        ]);
    }

    /** POST /orders/{order}/cancel/reject (admin) */
    public function reject(Request $request, Order $order)
    {
        $data = $request->validate([
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $cancel = $order->cancelRequests()
            ->where('status', CancelRequest::STATUS_PENDING)
            ->latest()
            ->first();

        if (!$cancel) {
            return response()->json(['message' => 'No pending cancel request for this order'], 404);
        }

        DB::transaction(function () use ($order, $cancel, $request, $data) {
            $cancel->update([
                'status'      => CancelRequest::STATUS_REJECTED,
                'admin_note'  => $data['admin_note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            // Restore whatever status the order was in before the request.
            $order->update(['status' => (int) $cancel->previous_status]);
        });

        return response()->json([
            'message'        => 'Cancel rejected',
            'cancel_request' => $cancel->fresh(),
            'order'          => $order->fresh()->load('latestCancelRequest.user:id,name'),
        ]);
    }

    /** GET /cancel-requests — seller: own; admin/support: all. ?status= filter. */
    public function index(Request $request)
    {
        $query = CancelRequest::with(['order:id,system_id,ref_id,status,paid_cost,total_cost', 'user:id,name'])
            ->latest();

        if ($request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(50));
    }
}
