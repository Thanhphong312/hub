<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Order::query();

        if ($user->hasRole('seller')) {
            $query->where('user_id', $user->id);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Active query = excludes cancelled orders. All revenue/cost/per-day
        // aggregates use this so cancelled orders don't inflate the numbers.
        $activeQuery = (clone $query)->where('status', '!=', Order::STATUS_CANCELLED);

        $totalOrders = (clone $activeQuery)->count();

        // Status breakdown keeps every status (incl. cancelled) so admins can
        // see how many were cancelled at a glance.
        $ordersByStatus = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn($count, $status) => [
                'status' => Order::STATUS_MAP[$status] ?? 'unknown',
                'status_id' => $status,
                'count' => $count,
            ])->values();

        $totalRevenue = (clone $activeQuery)->sum('total_cost');
        $totalPaid = (clone $activeQuery)->sum('paid_cost');
        $totalUnpaid = $totalRevenue - $totalPaid;
        $totalShippingCost = (clone $activeQuery)->sum('shipping_cost');
        $totalPrintCost = (clone $activeQuery)->sum('print_cost');

        // Orders per day (last 30 days) — exclude cancelled
        $ordersPerDay = (clone $activeQuery)
            ->where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'), DB::raw('SUM(total_cost) as revenue'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // ── End-of-month projection ───────────────────────────────────
        // Average orders/day for THIS calendar month (Vietnam-local, matching
        // the +7h convention used elsewhere) and a naive linear projection to
        // the full month. Built from a fresh, seller-scoped base so it ignores
        // any date_from/date_to filter on the page. The per-order profit rate
        // ($1.8) is applied client-side so operators can tweak it live.
        $monthBase = Order::query()->where('status', '!=', Order::STATUS_CANCELLED);
        if ($user->hasRole('seller')) {
            $monthBase->where('user_id', $user->id);
        }
        $vnNow = now('Asia/Ho_Chi_Minh');
        $monthOrders = $monthBase
            ->whereRaw("DATE_FORMAT(DATE_ADD(created_at, INTERVAL 7 HOUR), '%Y-%m') = ?", [$vnNow->format('Y-m')])
            ->count();
        $daysElapsed = (int) $vnNow->day;
        $daysInMonth = (int) $vnNow->daysInMonth;
        $avgOrdersPerDay = $daysElapsed > 0 ? $monthOrders / $daysElapsed : 0;
        $projectedMonthOrders = (int) round($avgOrdersPerDay * $daysInMonth);

        // Recent orders — keep all so admin can see the full timeline.
        $recentOrders = (clone $query)
            ->with('user')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        $stats = [
            'total_orders' => $totalOrders,
            'orders_by_status' => $ordersByStatus,
            'total_revenue' => round($totalRevenue, 2),
            'total_paid' => round($totalPaid, 2),
            'total_unpaid' => round($totalUnpaid, 2),
            'total_print_cost' => round($totalPrintCost, 2),
            'total_shipping_cost' => round($totalShippingCost, 2),
            'orders_per_day' => $ordersPerDay,
            'recent_orders' => $recentOrders,
            // End-of-month projection (current VN month).
            'avg_orders_per_day' => round($avgOrdersPerDay, 1),
            'month_orders' => $monthOrders,
            'days_elapsed' => $daysElapsed,
            'days_in_month' => $daysInMonth,
            'projected_month_orders' => $projectedMonthOrders,
        ];

        // Admin extra stats
        if ($user->hasRole('admin')) {
            $stats['total_users'] = User::count();
            $stats['total_sellers'] = User::whereHas('role', fn($q) => $q->where('slug', 'seller'))->count();
            $stats['total_wallet_balance'] = round(User::sum('wallet'), 2);

            // Top sellers — exclude cancelled orders so revenue is realistic.
            $stats['top_sellers'] = Order::where('status', '!=', Order::STATUS_CANCELLED)
                ->select('user_id', DB::raw('count(*) as order_count'), DB::raw('SUM(total_cost) as total_revenue'))
                ->groupBy('user_id')
                ->orderByDesc('order_count')
                ->limit(10)
                ->with('user:id,name,email')
                ->get();
        }

        return response()->json($stats);
    }

    /**
     * Shipped-orders-per-day report. Group key is a Vietnam-local calendar day
     * (+7h offset, since timestamps are stored UTC) of either created_at or
     * completed_time. Also reports how many shipped orders are missing
     * completed_time so the UI can warn that the "completed" view is partial.
     */
    public function shippedReport(Request $request)
    {
        $user = $request->user();
        $field = $request->input('date_field') === 'completed_time' ? 'completed_time' : 'created_at';
        $from = $request->input('date_from');
        $to   = $request->input('date_to');

        $base = Order::where('status', Order::STATUS_SHIPPED);
        if ($user->hasRole('seller')) {
            $base->where('user_id', $user->id);
        }

        // Health check on completed_time (answers "do orders have it yet?").
        $totalShipped     = (clone $base)->count();
        $missingCompleted = (clone $base)->whereNull('completed_time')->count();

        // VN-local day bucket. $field is whitelisted above, safe to interpolate.
        $dayExpr = "DATE(DATE_ADD({$field}, INTERVAL 7 HOUR))";

        $query = (clone $base)->whereNotNull($field);
        if ($from) $query->whereRaw("{$dayExpr} >= ?", [$from]);
        if ($to)   $query->whereRaw("{$dayExpr} <= ?", [$to]);

        $rows = $query
            ->select(
                DB::raw("{$dayExpr} as day"),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_cost) as revenue')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day'     => $r->day,
                'count'   => (int) $r->count,
                'revenue' => round((float) $r->revenue, 2),
            ]);

        return response()->json([
            'rows' => $rows,
            'meta' => [
                'date_field'        => $field,
                'date_from'         => $from,
                'date_to'           => $to,
                'total_shipped'     => $totalShipped,
                'missing_completed' => $missingCompleted,
                'total_count'       => (int) $rows->sum('count'),
                'total_revenue'     => round((float) $rows->sum('revenue'), 2),
            ],
        ]);
    }
}
