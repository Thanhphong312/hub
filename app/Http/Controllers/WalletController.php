<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
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
        ]);

        $transaction->update($request->only(['amount', 'method', 'transaction_id', 'note']));

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
