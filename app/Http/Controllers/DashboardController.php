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

        // Recent orders — keep all so admin can see the full timeline.
        $recentOrders = (clone $query)
            ->with('user')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        // Stale orders: created more than 24h ago and still NOT shipped (and not
        // cancelled) — an SLA warning. Fresh seller-scoped base so it ignores the
        // page date filter. Oldest first so the worst offenders surface.
        $staleBase = Order::query()
            ->whereNotIn('status', [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED])
            ->where('created_at', '<=', now()->subDay());
        if ($user->hasRole('seller')) {
            $staleBase->where('user_id', $user->id);
        }
        $staleCount = (clone $staleBase)->count();
        $staleItems = (clone $staleBase)
            ->with('user:id,name')
            ->orderBy('created_at')
            ->limit(100)
            ->get(['id', 'system_id', 'status', 'created_at', 'user_id'])
            ->map(fn ($o) => [
                'id'         => $o->id,
                'system_id'  => $o->system_id,
                'status'     => Order::STATUS_MAP[$o->status] ?? 'unknown',
                'status_id'  => $o->status,
                'created_at' => optional($o->created_at)->toIso8601String(),
                'user'       => $o->user?->name,
            ]);

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
            'stale_unshipped' => [
                'count' => $staleCount,
                'items' => $staleItems,
            ],
        ];

        // Tracking-ran rate: of orders shipped YESTERDAY (Vietnam-local
        // completed_time day, same +7h bucket as shippedReport), how many have
        // tracking that actually moved past label creation — i.e. an
        // order_trackings row whose status is set and != 'pre_shipment'. A low
        // rate means labels were created but the carrier hasn't scanned the
        // parcels yet (a "shipped but not really moving" warning).
        $yesterdayVn = now()->setTimezone('Asia/Ho_Chi_Minh')->subDay()->toDateString();
        $shippedYdayBase = Order::where('status', Order::STATUS_SHIPPED)
            ->whereNotNull('completed_time')
            ->whereRaw('DATE(DATE_ADD(completed_time, INTERVAL 7 HOUR)) = ?', [$yesterdayVn]);
        if ($user->hasRole('seller')) {
            $shippedYdayBase->where('user_id', $user->id);
        }
        $shippedYday = (clone $shippedYdayBase)->count();
        $trackingRan = (clone $shippedYdayBase)
            ->whereHas('tracking', fn ($q) => $q->whereNotNull('status')->where('status', '!=', 'pre_shipment'))
            ->count();
        $stats['shipped_tracking'] = [
            'date'         => $yesterdayVn,
            'shipped'      => $shippedYday,
            'tracking_ran' => $trackingRan,
            'rate'         => $shippedYday > 0 ? round($trackingRan / $shippedYday * 100, 1) : null,
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
