<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PushTracking;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Push scan: the seller asks for an order's tracking to be scanned/run the
 * same day. Pushing an order queues it in push_trackings and adds the
 * configured price (Settings → Push Scan) to the order:
 *   - unpaid order → push_scan_fee + total_cost grow; the fee is charged with
 *     the rest of the order on pay.
 *   - fully paid order → surcharge: the fee is debited from the owner's wallet
 *     right away (paid transaction noted "push scan"), paid_cost follows.
 */
class PushScanService
{
    /**
     * Push one order. Returns ['ok' => bool, 'message' => string, 'fee' => float, 'charged' => float].
     * Idempotent: an order already in push_trackings is reported and left alone.
     */
    public function push(Order $order, bool $free = false): array
    {
        // Shipped orders can still be pushed (re-run their tracking); only
        // cancelled orders are dead.
        if ((int) $order->status === Order::STATUS_CANCELLED) {
            return ['ok' => false, 'message' => "#{$order->system_id}: đơn đã cancelled", 'fee' => 0, 'charged' => 0];
        }
        if ($order->ship_type === Order::SHIP_STAMP) {
            return ['ok' => false, 'message' => "#{$order->system_id}: đơn stamp không có tracking", 'fee' => 0, 'charged' => 0];
        }

        return DB::transaction(function () use ($order, $free) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();
            if (PushTracking::where('order_id', $order->id)->exists()) {
                return ['ok' => false, 'message' => "#{$order->system_id}: đã push scan rồi", 'fee' => 0, 'charged' => 0];
            }

            // Staff can push free of charge: the order is queued with fee 0 and
            // neither total_cost nor the wallet moves.
            $fee = $free ? 0.0 : PushTracking::price();
            $total = round((float) $order->total_cost, 2);
            $paid = round((float) $order->paid_cost, 2);
            $isPaid = $paid > 0 && $paid >= $total;
            $charged = 0.0;

            $updates = [
                'push_scan_fee' => round((float) $order->push_scan_fee + $fee, 2),
                'total_cost' => round($total + $fee, 2),
            ];

            if ($isPaid && $fee > 0) {
                $user = $order->user()->lockForUpdate()->first();
                if (round((float) $user->wallet, 2) < $fee) {
                    return ['ok' => false, 'message' => "#{$order->system_id}: ví không đủ để trả phí push scan \${$fee}", 'fee' => $fee, 'charged' => 0];
                }
                $balanceBefore = (float) $user->wallet;
                $user->decrement('wallet', $fee);
                $updates['paid_cost'] = round($paid + $fee, 2);
                WalletTransaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'order_system_id' => $order->system_id,
                    'type' => 'paid',
                    'status' => WalletTransaction::STATUS_APPROVED,
                    'amount' => -$fee,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $user->fresh()->wallet,
                    'note' => "Surcharge push scan #{$order->system_id}",
                ]);
                $charged = $fee;
            }

            $order->update($updates);
            PushTracking::create([
                'order_id' => $order->id,
                'status' => PushTracking::STATUS_PENDING,
                'fee' => $fee,
            ]);

            return [
                'ok' => true,
                'message' => $free
                    ? "#{$order->system_id}: push scan (free)"
                    : ($charged > 0
                        ? "#{$order->system_id}: push scan, surcharge \${$fee} trừ vào ví"
                        : "#{$order->system_id}: push scan, +\${$fee} vào total"),
                'fee' => $fee,
                'charged' => $charged,
            ];
        });
    }

    /** Truthy cell/flag values that mean "push scan this order" (CSV + JSON). */
    public static function isFlagOn($value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'x'], true);
    }
}
