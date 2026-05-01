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
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:' . implode(',', WalletTransaction::METHODS),
            'transaction_id' => 'required|string|unique:wallet_transactions,transaction_id',
            'note' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request) {
            $user = User::findOrFail($request->user_id);
            $balanceBefore = (float) $user->wallet;
            $user->increment('wallet', $request->amount);

            WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'deposit',
                'method' => $request->method,
                'transaction_id' => $request->transaction_id,
                'amount' => $request->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $user->fresh()->wallet,
                'note' => $request->note ?? 'Deposit',
            ]);

            return response()->json([
                'message' => 'Deposit successful',
                'wallet' => $user->fresh()->wallet,
            ]);
        });
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
        return response()->json([
            'wallet' => $user->wallet,
            'total_deposited' => WalletTransaction::where('user_id', $user->id)->where('type', 'deposit')->sum('amount'),
            'total_paid' => abs(WalletTransaction::where('user_id', $user->id)->where('type', 'paid')->sum('amount')),
            'total_refunded' => WalletTransaction::where('user_id', $user->id)->where('type', 'refund')->sum('amount'),
        ]);
    }
}
