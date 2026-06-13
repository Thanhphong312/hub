<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderTracking;
use App\Services\ShipEngine\TrackingChecker;
use Illuminate\Http\Request;

/**
 * Admin config + manual control for the ShipEngine delivery-tracking cron
 * (`tracking:check`). The cron itself runs server-side via routes/console.php;
 * these endpoints just read/write its settings and let an operator trigger or
 * inspect it from the desktop app.
 */
class TrackingController extends Controller
{
    /** Current run settings (enabled, run_hours, batch_limit). */
    public function settings()
    {
        return response()->json(OrderTracking::settings());
    }

    /** Update run settings. */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled'     => 'required|boolean',
            'run_hours'   => 'present|array',
            'run_hours.*' => 'integer|min:0|max:23',
            'batch_limit' => 'required|integer|min:1|max:1000',
        ]);

        return response()->json(OrderTracking::saveSettings($data));
    }

    /** Counts for the dashboard / settings page. */
    public function stats()
    {
        $withTracking = Order::whereNotNull('tracking_id')->where('tracking_id', '!=', '')
            ->where('status', '!=', Order::STATUS_CANCELLED)->count();
        $delivered = OrderTracking::where('status', 'delivered')->count();
        $inTransit = OrderTracking::whereNotNull('date_intransit')->where('status', '!=', 'delivered')->count();
        $checked   = OrderTracking::count();
        // Per-status breakdown (one box per tracking status in the UI).
        $byStatus = OrderTracking::selectRaw("COALESCE(NULLIF(status, ''), 'unknown') as s, COUNT(*) as c")
            ->groupBy('s')->pluck('c', 's');

        return response()->json([
            'orders_with_tracking' => $withTracking,
            'tracked'              => $checked,
            'in_transit'           => $inTransit,
            'delivered'            => $delivered,
            // Not delivered yet (= still polled by the cron).
            'pending'              => max(0, $withTracking - $delivered),
            'never_checked'        => max(0, $withTracking - $checked),
            'by_status'            => $byStatus,
        ]);
    }

    /**
     * Process ONE batch now and return counts. This is the endpoint the
     * bullstart desktop app polls at the configured run hours (app-driven cron,
     * matching the auto-pay pattern). `checked` < `limit` means the pending
     * queue was drained, so the app can stop looping for this cycle.
     */
    public function run(Request $request, TrackingChecker $checker)
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'order' => 'nullable|string',
        ]);

        if (!$checker->hasKey()) {
            return response()->json(['ok' => false, 'message' => 'ShipEngine API key not configured'], 422);
        }

        $limit = (int) ($data['limit'] ?? 100);
        $result = $checker->run($limit, $data['order'] ?? null);

        return response()->json([
            'ok'     => true,
            'limit'  => $limit,
            'result' => $result,        // {checked,changed,delivered,intransit,errors}
            'drained' => $result['checked'] < $limit,
        ]);
    }
}
