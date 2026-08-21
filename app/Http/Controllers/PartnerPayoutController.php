<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PartnerPayout;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Paying partners for the printing. Admin-only — see routes/api.php.
 *
 * The counterpart of WalletController, which handles money coming IN from
 * sellers. What a partner is owed is never a stored balance: it is the sum of
 * partner_revenue on their shipped orders that no payout has settled yet, so
 * the number cannot drift away from the orders it came from.
 */
class PartnerPayoutController extends Controller
{
    /** Payout history, newest first. Optionally scoped to one partner. */
    public function index(Request $request)
    {
        $query = PartnerPayout::with('partner:id,name', 'creator:id,name')
            ->withCount('orders')
            ->orderByDesc('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        return response()->json($query->paginate(min(100, (int) $request->get('per_page', 25))));
    }

    /** One payout with the orders it settled. */
    public function show(PartnerPayout $payout)
    {
        return response()->json([
            'payout' => $payout->load('partner:id,name', 'creator:id,name'),
            'orders' => $payout->orders()
                ->orderBy('completed_time')
                ->get(['id', 'system_id', 'ref_id', 'completed_time', 'partner_revenue']),
        ]);
    }

    /** What a partner has earned but not been paid for. */
    public function outstanding(Request $request, User $partner)
    {
        abort_unless($partner->hasRole('partner'), 404, 'Not a partner');

        $orders = $this->unpaidOrders($partner)
            ->orderBy('completed_time')
            ->get(['id', 'system_id', 'ref_id', 'completed_time', 'partner_revenue', 'paid_cost', 'total_cost']);

        return response()->json([
            'partner' => ['id' => $partner->id, 'name' => $partner->name],
            'orders' => $orders,
            'total' => round((float) $orders->sum('partner_revenue'), 2),
            'count' => $orders->count(),
            // Orders shipped with no amount yet: they are owed something, but
            // how much is unknown until Tính tiền runs, so they cannot be
            // included in a payout and must not be silently ignored either.
            'unpriced' => Order::where('partner_id', $partner->id)
                ->where('status', Order::STATUS_SHIPPED)
                ->whereNull('partner_revenue')
                ->count(),
        ]);
    }

    /**
     * Record a payment to a partner and mark the orders it covers.
     *
     * The amount is summed from the orders rather than taken from the client,
     * for the same reason order totals are: a number typed into a form is not
     * evidence of anything. An explicit `amount` may still be sent when it
     * differs from that sum (a rounded transfer, an agreed adjustment) and is
     * kept alongside the computed figure rather than replacing it.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'order_ids' => 'present|array',
            'order_ids.*' => 'integer',
            'amount' => 'nullable|numeric|min:0',
            'method' => 'nullable|string|in:'.implode(',', PartnerPayout::METHODS),
            'transaction_id' => 'nullable|string|max:255',
            'period_from' => 'nullable|date',
            'period_to' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
        ]);

        $partner = User::findOrFail($data['user_id']);
        abort_unless($partner->hasRole('partner'), 422, 'User này không phải partner');

        return DB::transaction(function () use ($data, $partner, $request) {
            // Re-read inside the transaction and lock: two admins paying the
            // same partner at once would otherwise both see the orders as
            // unpaid and settle them twice.
            $orders = $this->unpaidOrders($partner)
                ->when(! empty($data['order_ids']), fn ($q) => $q->whereIn('id', $data['order_ids']))
                ->lockForUpdate()
                ->get(['id', 'partner_revenue']);

            if ($orders->isEmpty() && ($data['amount'] ?? 0) <= 0) {
                return response()->json([
                    'message' => 'Không có đơn nào chưa trả, và cũng không nhập số tiền.',
                ], 422);
            }

            $computed = round((float) $orders->sum('partner_revenue'), 2);
            $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : $computed;

            $payout = PartnerPayout::create([
                'user_id' => $partner->id,
                'amount' => $amount,
                'orders_count' => $orders->count(),
                'method' => $data['method'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'period_from' => $data['period_from'] ?? null,
                'period_to' => $data['period_to'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            if ($orders->isNotEmpty()) {
                Order::whereIn('id', $orders->pluck('id'))->update(['partner_payout_id' => $payout->id]);
            }

            // Stamped after the orders move, so it reflects what is left.
            $payout->update(['balance_after' => $this->outstandingTotal($partner)]);

            return response()->json([
                'message' => 'Đã ghi nhận trả $'.number_format($amount, 2)." cho {$partner->name}"
                    .($orders->count() ? " · chốt {$orders->count()} đơn" : '')
                    .(abs($amount - $computed) >= 0.01 ? ' · lệch $'.number_format($amount - $computed, 2).' so với tổng đơn' : ''),
                'payout' => $payout->fresh(),
                'computed' => $computed,
                'balance_after' => $payout->fresh()->balance_after,
            ], 201);
        });
    }

    /**
     * Undo a payout: the orders it settled go back to unpaid.
     *
     * Deleting the row alone would leave them pointing at nothing — the FK is
     * nullOnDelete, so they would silently become unpaid anyway; doing it
     * explicitly keeps the count in the response honest.
     */
    public function destroy(PartnerPayout $payout)
    {
        $partner = $payout->partner;

        $released = DB::transaction(function () use ($payout) {
            $n = Order::where('partner_payout_id', $payout->id)->update(['partner_payout_id' => null]);
            $payout->delete();

            return $n;
        });

        return response()->json([
            'message' => "Đã huỷ giao dịch · trả {$released} đơn về chưa thanh toán",
            'released' => $released,
            'outstanding' => $partner ? $this->outstandingTotal($partner) : null,
        ]);
    }

    /**
     * Shipped orders carrying an amount that no payout has settled.
     *
     * Locked orders are included: partner_locked_at means the amount is final,
     * which is exactly when it becomes payable. It is the payout, not the lock,
     * that marks money as handed over.
     */
    private function unpaidOrders(User $partner)
    {
        return Order::where('partner_id', $partner->id)
            ->where('status', Order::STATUS_SHIPPED)
            ->whereNotNull('partner_revenue')
            ->whereNull('partner_payout_id');
    }

    private function outstandingTotal(User $partner): float
    {
        return round((float) $this->unpaidOrders($partner)->sum('partner_revenue'), 2);
    }
}
