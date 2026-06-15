<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
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

    /**
     * On-demand check for an explicit set of orders (the dashboard "Check
     * tracking" button on selected "chưa run" orders). Calls ShipEngine and
     * upserts order_trackings; returns which orders changed status.
     */
    public function checkSelected(Request $request, TrackingChecker $checker)
    {
        $data = $request->validate([
            'order_ids'   => 'required|array|min:1|max:300',
            'order_ids.*' => 'integer',
        ]);

        if (!$checker->hasKey()) {
            return response()->json(['message' => 'Chưa cấu hình ShipEngine API key'], 422);
        }

        $res = $checker->runForIds($data['order_ids']);
        $s   = $res['summary'];

        return response()->json([
            'message' => "Đã check {$s['checked']} đơn · đổi {$s['changed']} · lỗi {$s['errors']}",
            'summary' => $s,
            'results' => $res['results'],
        ]);
    }

    /** Whether a ShipEngine key is configured + where from (app vs server env). */
    public function shipengineKey()
    {
        $cfg = AppSetting::getJson('shipengine', []);
        $cfg = is_array($cfg) ? $cfg : [];

        return response()->json([
            'has_key'      => !empty($cfg['api_key']) || !empty(config('shipengine.api_key')),
            'from_app'     => !empty($cfg['api_key']),
            'carrier_code' => $cfg['carrier_code'] ?? config('shipengine.carrier_code', 'usps'),
        ]);
    }

    /**
     * Save the ShipEngine API key (and optional carrier) from the desktop app.
     * The raw key is never returned by GET; an empty api_key clears the override
     * so it falls back to the server .env.
     */
    public function updateShipengineKey(Request $request)
    {
        $data = $request->validate([
            'api_key'      => 'nullable|string|max:255',
            'carrier_code' => 'nullable|string|max:32',
        ]);

        $cfg = AppSetting::getJson('shipengine', []);
        $cfg = is_array($cfg) ? $cfg : [];

        if ($request->has('api_key')) {
            $cfg['api_key'] = trim((string) ($data['api_key'] ?? ''));
        }
        if ($request->filled('carrier_code')) {
            $cfg['carrier_code'] = trim($data['carrier_code']);
        }
        AppSetting::setJson('shipengine', $cfg);

        return response()->json([
            'ok'       => true,
            'has_key'  => !empty($cfg['api_key']) || !empty(config('shipengine.api_key')),
            'from_app' => !empty($cfg['api_key']),
        ]);
    }
}
