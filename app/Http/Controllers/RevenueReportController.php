<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Revenue summary for a period (admin only): seller top-ups, order totals
 * per seller, and what was split to each partner.
 *
 * Days are UTC calendar days — the same window the Orders list/export
 * filter uses (whereDate on created_at), so the totals match an export of
 * the same dates.
 */
class RevenueReportController extends Controller
{
    private const TZ = 'UTC';

    /**
     * GET /api/revenue-report
     *   date_from, date_to   YYYY-MM-DD (UTC days, inclusive)
     *   date_field           created_at | completed_time — which order date
     *                        the order + partner tables filter on
     */
    public function summary(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'date_field' => 'nullable|in:created_at,completed_time',
        ]);

        $tz = self::TZ;
        $from = Carbon::parse($request->date_from, $tz)->startOfDay();
        $to = Carbon::parse($request->date_to, $tz)->endOfDay();
        $dateField = $request->input('date_field', 'created_at');

        // 1) Top-ups: approved deposits into seller wallets.
        $topups = WalletTransaction::query()
            ->where('type', 'deposit')
            ->where('status', WalletTransaction::STATUS_APPROVED)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id')
            ->get([
                'user_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as amount'),
                DB::raw('SUM(COALESCE(vnd_amount, 0)) as vnd_amount'),
            ]);

        // 2) Orders (cancelled excluded): total_cost per seller.
        $orders = Order::query()
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->whereBetween($dateField, [$from, $to])
            ->groupBy('user_id')
            ->get([
                'user_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_cost) as total_cost'),
                DB::raw('SUM(paid_cost) as paid_cost'),
            ]);

        // 3) Partner split: partner_revenue per partner, same order filter.
        $partners = Order::query()
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->whereNotNull('partner_id')
            ->whereBetween($dateField, [$from, $to])
            ->groupBy('partner_id')
            ->get([
                'partner_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(COALESCE(partner_revenue, 0)) as partner_revenue'),
                DB::raw('SUM(total_cost) as total_cost'),
                DB::raw('SUM(CASE WHEN partner_revenue IS NULL THEN 1 ELSE 0 END) as unpriced'),
            ]);

        $names = User::whereIn('id', $topups->pluck('user_id')
            ->merge($orders->pluck('user_id'))
            ->merge($partners->pluck('partner_id'))
            ->filter()->unique())
            ->get(['id', 'name', 'email'])->keyBy('id');
        $who = fn ($id) => [
            'id' => $id,
            'name' => $names[$id]->name ?? "#{$id}",
            'email' => $names[$id]->email ?? null,
        ];
        $money = fn ($v) => round((float) $v, 2);

        $topupRows = $topups->map(fn ($r) => [
            'user' => $who($r->user_id),
            'count' => (int) $r->count,
            'amount' => $money($r->amount),
            'vnd_amount' => (float) $r->vnd_amount,
        ])->sortByDesc('amount')->values();

        $orderRows = $orders->map(fn ($r) => [
            'user' => $who($r->user_id),
            'count' => (int) $r->count,
            'total_cost' => $money($r->total_cost),
            'paid_cost' => $money($r->paid_cost),
        ])->sortByDesc('total_cost')->values();

        $partnerRows = $partners->map(fn ($r) => [
            'user' => $who($r->partner_id),
            'count' => (int) $r->count,
            'partner_revenue' => $money($r->partner_revenue),
            'total_cost' => $money($r->total_cost),
            'unpriced' => (int) $r->unpriced,
        ])->sortByDesc('partner_revenue')->values();

        return response()->json([
            'timezone' => $tz,
            'date_from' => $request->date_from,
            'date_to' => $request->date_to,
            'date_field' => $dateField,
            'topups' => [
                'rows' => $topupRows,
                'total' => [
                    'count' => $topupRows->sum('count'),
                    'amount' => $money($topupRows->sum('amount')),
                    'vnd_amount' => (float) $topupRows->sum('vnd_amount'),
                ],
            ],
            'orders' => [
                'rows' => $orderRows,
                'total' => [
                    'count' => $orderRows->sum('count'),
                    'total_cost' => $money($orderRows->sum('total_cost')),
                    'paid_cost' => $money($orderRows->sum('paid_cost')),
                ],
            ],
            'partners' => [
                'rows' => $partnerRows,
                'total' => [
                    'count' => $partnerRows->sum('count'),
                    'partner_revenue' => $money($partnerRows->sum('partner_revenue')),
                    'total_cost' => $money($partnerRows->sum('total_cost')),
                    'unpriced' => $partnerRows->sum('unpriced'),
                ],
            ],
        ]);
    }
}
