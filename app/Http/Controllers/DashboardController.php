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
        // Sundays don't count toward the "1 day" SLA (no shipping happens), so an
        // order sitting over the weekend isn't flagged a day early. When the
        // trailing 24h window has crossed a Sunday (Chicago time), push the cutoff
        // back one extra day of grace.
        $nowChi      = now()->setTimezone('America/Chicago');
        $staleCutoff = now()->subDay();
        if ($nowChi->isSunday() || $nowChi->isMonday()) {
            $staleCutoff = $staleCutoff->subDay();
        }
        $staleBase = Order::query()
            ->whereNotIn('status', [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED])
            ->where('created_at', '<=', $staleCutoff);
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

        // Tracking-ran rate over the last 7 days, EXCLUDING today (today's orders
        // were just shipped — tracking hasn't had time to move yet). Days are
        // bucketed in US Central time (America/Chicago); we compute the window
        // edges in Chicago then convert to UTC so the comparison against the
        // UTC-stored completed_time is DST-safe (no fixed-offset hack). "Ran" =
        // an order_trackings row whose status is set and != 'pre_shipment'.
        $tz      = 'America/Chicago';
        $nowCt   = now()->setTimezone($tz);
        $startCt = $nowCt->copy()->startOfDay()->subDays(7);    // midnight Chicago, 7 days ago
        $endCt   = $nowCt->copy()->startOfDay();                // midnight Chicago today (excl.)

        // USPS doesn't scan packages on Sundays, so an order shipped on a Sunday
        // that is still at 'pre_shipment' isn't actually late — the carrier just
        // hasn't worked yet. Exclude those from BOTH numerator and denominator so
        // they don't drag the rate down. The 7-day window spans exactly one
        // Sunday; find it in Chicago local time and convert its day bounds to UTC
        // for the completed_time comparison (DST-safe via Carbon).
        $sunCt = $startCt->copy();
        while (! $sunCt->isSunday()) {
            $sunCt->addDay();
        }
        $sunStartUtc = $sunCt->copy()->startOfDay()->utc();
        $sunEndUtc   = $sunCt->copy()->addDay()->startOfDay()->utc();
        // Only orders ELIGIBLE for a tracking judgement count toward the ratio:
        // shipped in-window AND they have a tracking_id AND an order_trackings
        // row with a status (carrier checked at least once). Orders with no
        // tracking_id / never checked are excluded from both numerator and
        // denominator. Among the eligible:
        //   "ran"     = status != 'pre_shipment' OR events_count >= 3
        //   "not run" = status == 'pre_shipment' AND events_count < 3
        // (>= 3 scan events counts as run even if ShipEngine still reports the
        // status_code as NY/pre_shipment — the package is clearly moving.)
        $ran    = fn ($t) => $t->where(fn ($q) => $q
            ->where('status', '!=', 'pre_shipment')
            ->orWhere('events_count', '>=', 3));
        $notRun = fn ($t) => $t->where('status', 'pre_shipment')
            ->where(fn ($q) => $q->where('events_count', '<', 3)->orWhereNull('events_count'));

        $shippedRangeBase = Order::where('status', Order::STATUS_SHIPPED)
            ->whereNotNull('completed_time')
            ->where('completed_time', '>=', $startCt->copy()->utc())
            ->where('completed_time', '<', $endCt->copy()->utc())
            ->whereNotNull('tracking_id')->where('tracking_id', '!=', '')
            ->whereHas('tracking', fn ($q) => $q->whereNotNull('status'))
            // Drop Sunday-shipped orders that are still pre_shipment (USPS closed).
            // Keep: anything not shipped on the Sunday, OR shipped Sunday but already running.
            ->where(function ($q) use ($sunStartUtc, $sunEndUtc, $ran) {
                $q->where(fn ($q2) => $q2
                        ->where('completed_time', '<', $sunStartUtc)
                        ->orWhere('completed_time', '>=', $sunEndUtc))
                  ->orWhereHas('tracking', $ran);
            });
        if ($user->hasRole('seller')) {
            $shippedRangeBase->where('user_id', $user->id);
        }
        $shippedCount = (clone $shippedRangeBase)->count();
        $trackingRan = (clone $shippedRangeBase)
            ->whereHas('tracking', $ran)
            ->count();

        // The "chưa run" CHASE LIST is intentionally NOT capped to the 7-day
        // window: an order shipped >7 days ago and still at pre_shipment is the
        // MOST overdue one to chase, yet the windowed query would hide it. So we
        // pull EVERY shipped order that has a tracking_id, was carrier-checked,
        // and is still not-run — only excluding today's just-shipped orders
        // (they haven't had time to move). The rate% above stays on 7 days.
        $notRunBase = Order::where('status', Order::STATUS_SHIPPED)
            ->whereNotNull('completed_time')
            ->where('completed_time', '<', $endCt->copy()->utc())   // exclude today (just shipped)
            ->whereNotNull('tracking_id')->where('tracking_id', '!=', '')
            ->whereHas('tracking', fn ($q) => $q->whereNotNull('status'))
            ->whereHas('tracking', $notRun);
        if ($user->hasRole('seller')) {
            $notRunBase->where('user_id', $user->id);
        }

        $notRunTotal  = (clone $notRunBase)->count();
        $notRunOrders = (clone $notRunBase)
            ->with(['user:id,name', 'tracking:id,order_id,status'])
            ->orderBy('user_id')
            ->orderByDesc('completed_time')
            ->limit(2000)
            ->get(['id', 'system_id', 'user_id', 'completed_time']);
        // Map a single order to the compact shape the UI chips render.
        $orderShape = fn ($o) => [
            'id'        => $o->id,
            'system_id' => $o->system_id,
            'status'    => $o->tracking?->status ?: 'none',
        ];
        $sellerGroup = fn ($g) => [
            'user_id' => $g->first()->user_id,
            'user'    => $g->first()->user?->name,
            'count'   => $g->count(),
            'orders'  => $g->map($orderShape)->values(),
        ];

        $notRunBySeller = $notRunOrders
            ->groupBy('user_id')
            ->map($sellerGroup)
            ->sortByDesc('count')
            ->values();

        // Same backlog, but bucketed by ship day (completed_time in Chicago tz —
        // matches the window math above), each day split per seller. Newest day
        // first. Lets the dashboard show "chưa run" divided by day.
        $notRunByDay = $notRunOrders
            ->groupBy(fn ($o) => \Illuminate\Support\Carbon::parse($o->completed_time)->setTimezone($tz)->toDateString())
            ->map(fn ($dayOrders, $date) => [
                'date'    => $date,
                'count'   => $dayOrders->count(),
                'sellers' => $dayOrders->groupBy('user_id')->map($sellerGroup)->sortByDesc('count')->values(),
            ])
            ->sortKeysDesc()
            ->values();

        $stats['shipped_tracking'] = [
            'from'              => $startCt->toDateString(),
            'to'                => $nowCt->copy()->subDay()->toDateString(),
            'timezone'          => $tz,
            'shipped'           => $shippedCount,
            'tracking_ran'      => $trackingRan,
            'not_run'           => $notRunTotal,
            'not_run_by_day'    => $notRunByDay,
            'rate'              => $shippedCount > 0 ? round($trackingRan / $shippedCount * 100, 2) : null,
            'not_run_by_seller' => $notRunBySeller,
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
