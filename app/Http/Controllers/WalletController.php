<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Wallet
 *
 * Balance, deposits (manual + VNPay), paid history, refunds.
 */
class WalletController extends Controller
{
    /**
     * List transactions
     *
     * @authenticated
     * @queryParam type string `deposit`, `paid`, `refund`. Example: paid
     * @queryParam user_id int (admin only) Filter theo owner.
     * @queryParam date_from string YYYY-MM-DD.
     * @queryParam date_to string YYYY-MM-DD.
     * @queryParam page int
     * @queryParam per_page int Default 20.
     */
    public function transactions(Request $request)
    {
        $user = $request->user();
        $query = WalletTransaction::with(['order:id,system_id,ref_id,total_cost', 'user:id,name,email']);

        if ($user->hasRole('seller')) {
            $query->where('user_id', $user->id);
        }

        if ($request->has('user_id') && !$user->hasRole('seller')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 20));
        return response()->json($transactions);
    }

    /**
     * GET /api/wallet/transactions/export — stream CSV of transactions
     * matching the same filters as `transactions()`. Useful for accounting:
     * select tab = "Paid" + date range, hit Export, hand the file to finance.
     *
     * Streamed so even tens of thousands of rows don't OOM PHP. Filters mirror
     * `transactions()` 1:1 — sellers see only their own rows (server-enforced),
     * admin can optionally filter by user_id.
     */
    public function exportTransactions(Request $request)
    {
        $user = $request->user();
        $query = WalletTransaction::with(['order:id,system_id,ref_id,total_cost', 'user:id,name,email']);

        if ($user->hasRole('seller')) {
            $query->where('user_id', $user->id);
        }
        if ($request->filled('user_id') && !$user->hasRole('seller')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $headers = [
            'id', 'created_at', 'user_name', 'user_email',
            'type', 'status', 'method',
            'transaction_id', 'order_system_id', 'order_ref_id',
            'amount', 'balance_before', 'balance_after',
            'note',
        ];

        $type = $request->get('type', 'all');
        $filename = "wallet_{$type}_" . now()->format('Ymd_His') . '.csv';

        $callback = function () use ($query, $headers) {
            $fh = fopen('php://output', 'w');
            // UTF-8 BOM for Excel
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $headers);

            // lazy() streams from the DB cursor without preloading or
            // chunk-paginating, so combining with orderBy('id','desc') is
            // safe (chunkById conflicts with non-asc-id ordering and could
            // skip rows at chunk boundaries).
            $query->orderBy('id', 'desc')->lazy(500)->each(function ($t) use ($fh) {
                fputcsv($fh, [
                    $t->id,
                    optional($t->created_at)->format('Y-m-d H:i:s'),
                    $t->user?->name ?? '',
                    $t->user?->email ?? '',
                    $t->type,
                    $t->status,
                    $t->method ?? '',
                    $t->transaction_id ?? '',
                    $t->order?->system_id ?? $t->order_system_id ?? '',
                    $t->order?->ref_id ?? '',
                    // Keep sign (paid/refund are stored negative/positive
                    // — accountants want to see direction).
                    number_format((float) $t->amount, 2, '.', ''),
                    $t->balance_before !== null ? number_format((float) $t->balance_before, 2, '.', '') : '',
                    $t->balance_after  !== null ? number_format((float) $t->balance_after,  2, '.', '') : '',
                    $t->note ?? '',
                ]);
            });

            fclose($fh);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @OA\Post(
     *     path="/wallet/deposit",
     *     tags={"Wallet"},
     *     summary="Submit deposit request",
     *     description="Tạo deposit request (status=pending). Admin sẽ approve qua /wallet/deposit/{id}/approve để cộng tiền vào ví. Seller tự deposit vào ví của chính mình; admin có thể deposit cho user khác qua user_id.",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount","method","transaction_id"},
     *             @OA\Property(property="user_id",        type="integer", example=2, description="Admin only — target user. Seller auto = self."),
     *             @OA\Property(property="amount",         type="number",  example=100.50, minimum=0.01),
     *             @OA\Property(property="method",         type="string",  enum={"paypal","pingpong"}),
     *             @OA\Property(property="transaction_id", type="string",  example="PP-9F87B342K"),
     *             @OA\Property(property="note",           type="string",  example="Top-up via PayPal")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Deposit pending approval"),
     *     @OA\Response(response=422, description="Validation error (duplicate transaction_id, etc.)")
     * )
     */
    public function deposit(Request $request)
    {
        $request->validate([
            'user_id' => 'sometimes|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:' . implode(',', WalletTransaction::METHODS),
            'transaction_id' => 'required|string|unique:wallet_transactions,transaction_id',
            'note' => 'nullable|string',
        ]);

        // Sellers can only deposit into their own wallet; admin can target any user.
        $authUser = $request->user();
        $targetUserId = $authUser->hasRole('seller')
            ? $authUser->id
            : ($request->user_id ?? $authUser->id);

        $tx = WalletTransaction::create([
            'user_id' => $targetUserId,
            'type' => 'deposit',
            'status' => WalletTransaction::STATUS_PENDING,
            'method' => $request->method,
            'transaction_id' => $request->transaction_id,
            'amount' => $request->amount,
            'note' => $request->note ?? 'Deposit',
        ]);

        return response()->json([
            'message' => 'Deposit submitted (pending approval)',
            'transaction' => $tx,
        ]);
    }

    public function approveDeposit(Request $request, WalletTransaction $transaction)
    {
        if ($transaction->type !== 'deposit') {
            return response()->json(['message' => 'Only deposits can be approved'], 400);
        }
        if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
            return response()->json(['message' => 'Transaction is not pending'], 400);
        }

        return DB::transaction(function () use ($transaction) {
            $user = User::findOrFail($transaction->user_id);
            $balanceBefore = (float) $user->wallet;
            $user->increment('wallet', $transaction->amount);

            $transaction->update([
                'status' => WalletTransaction::STATUS_APPROVED,
                'balance_before' => $balanceBefore,
                'balance_after' => $user->fresh()->wallet,
            ]);

            return response()->json([
                'message' => 'Deposit approved',
                'transaction' => $transaction->fresh(),
                'wallet' => $user->fresh()->wallet,
            ]);
        });
    }

    public function updateDeposit(Request $request, WalletTransaction $transaction)
    {
        if ($transaction->type !== 'deposit') {
            return response()->json(['message' => 'Only deposit transactions are editable'], 400);
        }
        if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
            return response()->json(['message' => 'Approved transactions cannot be edited'], 403);
        }

        $authUser = $request->user();
        $isOwner = $transaction->user_id === $authUser->id;
        $isAdmin = $authUser->hasRole('admin');
        if (!$isOwner && !$isAdmin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'method' => 'sometimes|in:' . implode(',', WalletTransaction::METHODS),
            'transaction_id' => 'sometimes|string|unique:wallet_transactions,transaction_id,' . $transaction->id,
            'note' => 'nullable|string',
            // Admin-only: reassign the deposit to a different user before approval.
            // The validate-or-strip below blocks sellers from sending it.
            'user_id' => 'sometimes|exists:users,id',
        ]);

        $fields = ['amount', 'method', 'transaction_id', 'note'];
        if ($isAdmin) $fields[] = 'user_id';
        $transaction->update($request->only($fields));

        return response()->json([
            'message' => 'Deposit updated',
            'transaction' => $transaction->fresh(),
        ]);
    }

    public function refund(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string',
        ]);

        $order = \App\Models\Order::findOrFail($request->order_id);
        $user = $order->user;

        if ($request->amount > $order->paid_cost) {
            return response()->json(['message' => 'Refund amount exceeds paid amount'], 400);
        }

        return DB::transaction(function () use ($user, $order, $request) {
            $balanceBefore = (float) $user->wallet;
            $user->increment('wallet', $request->amount);
            $order->decrement('paid_cost', $request->amount);

            WalletTransaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'order_system_id' => $order->system_id,
                'type' => 'refund',
                'status' => WalletTransaction::STATUS_APPROVED,
                'amount' => $request->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $user->fresh()->wallet,
                'note' => $request->note ?? "Refund order #{$order->system_id}",
            ]);

            return response()->json([
                'message' => 'Refund successful',
                'wallet' => $user->fresh()->wallet,
                'order' => $order->fresh(),
            ]);
        });
    }

    /**
     * POST /api/wallet/transactions/bulk-refund-delete (admin only).
     *
     * For each selected `paid` transaction:
     *   1. Credit the user's wallet by |amount| (paid txns are stored as
     *      negative amounts; refund reverses that direction).
     *   2. Subtract |amount| from the linked order's paid_cost so the
     *      order shows as unpaid again. paid_cost can't go below 0.
     *   3. Delete the original wallet_transactions row entirely.
     *
     * Wrapped in a DB::transaction so a single failure rolls back the
     * whole batch — partial refunds are never persisted.
     */
    public function bulkRefundDelete(Request $request)
    {
        $request->validate([
            'transaction_ids'   => 'required|array|min:1',
            'transaction_ids.*' => 'integer|exists:wallet_transactions,id',
        ]);

        $txs = WalletTransaction::with('order:id,system_id,paid_cost')
            ->whereIn('id', $request->transaction_ids)
            ->lockForUpdate()
            ->get();

        $errors = [];
        $refundedCount = 0;
        $totalRefunded = 0.0;

        DB::transaction(function () use ($txs, &$errors, &$refundedCount, &$totalRefunded) {
            foreach ($txs as $tx) {
                if ($tx->type !== 'paid') {
                    $errors[] = "Tx #{$tx->id}: only `paid` transactions can be refund-deleted (got `{$tx->type}`)";
                    continue;
                }

                $amount = abs((float) $tx->amount);
                if ($amount <= 0) {
                    $errors[] = "Tx #{$tx->id}: amount is 0, skipping";
                    continue;
                }

                $user = \App\Models\User::lockForUpdate()->find($tx->user_id);
                if (!$user) {
                    $errors[] = "Tx #{$tx->id}: user #{$tx->user_id} not found";
                    continue;
                }

                $user->increment('wallet', $amount);

                if ($tx->order) {
                    // Clamp paid_cost ≥ 0 to guard against double-refund or
                    // a stale txn that already had its paid_cost reverted.
                    $newPaid = max(0, (float) $tx->order->paid_cost - $amount);
                    $tx->order->update(['paid_cost' => $newPaid]);
                }

                $tx->delete();
                $refundedCount++;
                $totalRefunded += $amount;
            }
        });

        return response()->json([
            'message'         => "Refunded {$refundedCount} transaction(s), total \$" . number_format($totalRefunded, 2),
            'refunded_count'  => $refundedCount,
            'total_refunded'  => $totalRefunded,
            'errors'          => $errors,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/wallet/balance",
     *     tags={"Wallet"},
     *     summary="Get wallet balance + lifetime totals",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Balance summary",
     *         @OA\JsonContent(
     *             @OA\Property(property="wallet",            type="number", example=523.50),
     *             @OA\Property(property="total_deposited",   type="number", example=2000.00),
     *             @OA\Property(property="total_paid",        type="number", example=1476.50),
     *             @OA\Property(property="total_refunded",    type="number", example=0.00),
     *             @OA\Property(property="pending_deposits",  type="number", example=100.00)
     *         )
     *     )
     * )
     */
    public function balance(Request $request)
    {
        $user = $request->user();
        $approved = fn($q) => $q->where('user_id', $user->id)->where('status', WalletTransaction::STATUS_APPROVED);
        return response()->json([
            'wallet' => $user->wallet,
            'total_deposited' => $approved(WalletTransaction::where('type', 'deposit'))->sum('amount'),
            'total_paid' => abs($approved(WalletTransaction::where('type', 'paid'))->sum('amount')),
            'total_refunded' => $approved(WalletTransaction::where('type', 'refund'))->sum('amount'),
            'pending_deposits' => WalletTransaction::where('user_id', $user->id)
                ->where('type', 'deposit')
                ->where('status', WalletTransaction::STATUS_PENDING)
                ->sum('amount'),
        ]);
    }
}
