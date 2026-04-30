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

        $totalOrders = (clone $query)->count();

        $ordersByStatus = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn($count, $status) => [
                'status' => Order::STATUS_MAP[$status] ?? 'unknown',
                'status_id' => $status,
                'count' => $count,
            ])->values();

        $totalRevenue = (clone $query)->sum('total_cost');
        $totalPaid = (clone $query)->sum('paid_cost');
        $totalUnpaid = $totalRevenue - $totalPaid;
        $totalShippingCost = (clone $query)->sum('shipping_cost');
        $totalPrintCost = (clone $query)->sum('print_cost');

        // Orders per day (last 30 days)
        $ordersPerDay = (clone $query)
            ->where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'), DB::raw('SUM(total_cost) as revenue'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Recent orders
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
        ];

        // Admin extra stats
        if ($user->hasRole('admin')) {
            $stats['total_users'] = User::count();
            $stats['total_sellers'] = User::whereHas('role', fn($q) => $q->where('slug', 'seller'))->count();
            $stats['total_wallet_balance'] = round(User::sum('wallet'), 2);

            // Top sellers
            $stats['top_sellers'] = Order::select('user_id', DB::raw('count(*) as order_count'), DB::raw('SUM(total_cost) as total_revenue'))
                ->groupBy('user_id')
                ->orderByDesc('order_count')
                ->limit(10)
                ->with('user:id,name,email')
                ->get();
        }

        return response()->json($stats);
    }
}
