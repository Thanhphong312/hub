<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

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

        return response()->json([
            'orders_with_tracking' => $withTracking,
            'tracked'              => $checked,
            'in_transit'           => $inTransit,
            'delivered'            => $delivered,
            // Not delivered yet (= still polled by the cron).
            'pending'              => max(0, $withTracking - $delivered),
            'never_checked'        => max(0, $withTracking - $checked),
        ]);
    }

    /**
     * Trigger a check now (admin button). Runs synchronously with a capped
     * limit so the request doesn't hang; returns the command output.
     */
    public function run(Request $request)
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'order' => 'nullable|string',
        ]);

        $params = ['--force' => true, '--limit' => $data['limit'] ?? 50];
        if (!empty($data['order'])) {
            $params['--order'] = $data['order'];
        }

        Artisan::call('tracking:check', $params);

        return response()->json([
            'ok'     => true,
            'output' => Artisan::output(),
        ]);
    }
}
