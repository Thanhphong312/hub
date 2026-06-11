<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderTracking;
use App\Services\ShipEngine\ShipEngineTrackingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Poll ShipEngine for the delivery status of orders that have a tracking_id and
 * aren't delivered yet. Writes/updates one row per order in `order_trackings`.
 *
 * Rules (per spec):
 *   - An order with >= 3 tracking events counts as in-transit; date_intransit
 *     is set ONCE from the 3rd event's time.
 *   - When status_description changes the row is updated.
 *   - Once an order is delivered it's no longer polled (query excludes it).
 *
 * Scheduling: routes/console.php runs this hourly; it self-gates on the
 * AppSetting `tracking_check.run_hours` (Vietnam-local) unless --force is given.
 */
class CheckTracking extends Command
{
    protected $signature = 'tracking:check
        {--limit= : override the per-run batch limit}
        {--force : ignore the enabled flag and run-hour gate}
        {--order= : check a single order by id or system_id}';

    protected $description = 'Poll ShipEngine for delivery status of orders with a tracking_id (until delivered).';

    /** ShipEngine rate-limit friendly: how many concurrent lookups per pool. */
    private const POOL_SIZE = 20;

    public function handle(ShipEngineTrackingService $svc): int
    {
        if (!$svc->hasKey()) {
            $this->error('SHIPENGINE_API_KEY is not configured.');
            return self::FAILURE;
        }

        $cfg    = OrderTracking::settings();
        $single = $this->option('order');
        $force  = (bool) $this->option('force');

        // Gate on enabled + selected hour (Vietnam-local) for the scheduled run.
        if (!$single && !$force) {
            if (empty($cfg['enabled'])) {
                $this->info('tracking:check is disabled in settings.');
                return self::SUCCESS;
            }
            $hour  = (int) now('Asia/Ho_Chi_Minh')->format('G');
            $hours = array_map('intval', $cfg['run_hours'] ?? []);
            if (!in_array($hour, $hours, true)) {
                $this->info("Hour {$hour} (VN) not in run_hours [" . implode(',', $hours) . '] — skipping.');
                return self::SUCCESS;
            }
        }

        $limit = max(1, (int) ($this->option('limit') ?: ($cfg['batch_limit'] ?? 100)));

        $orders = $this->pendingOrders($single, $limit);
        if ($orders->isEmpty()) {
            $this->info('No orders pending a tracking check.');
            return self::SUCCESS;
        }

        $this->info("Checking {$orders->count()} order(s)…");
        $updated = $changed = $delivered = $intransit = $errors = 0;

        foreach ($orders->chunk(self::POOL_SIZE) as $chunk) {
            $list    = $chunk->values();
            $numbers = $list->pluck('tracking_id')->all();
            $results = $svc->getMultipleTrackingInfo($numbers);

            foreach ($list as $i => $order) {
                $res = $results[$i] ?? null;
                if (!$res || empty($res['success'])) {
                    $errors++;
                    $msg = $res['error'] ?? 'no result';
                    $this->warn("[{$order->system_id}] {$msg}");
                    Log::warning('tracking:check failed', ['order' => $order->system_id, 'error' => $msg]);
                    continue;
                }

                $outcome = $this->applyResult($order, $res);
                $updated++;
                if ($outcome['changed'])   { $changed++; }
                if ($outcome['intransit']) { $intransit++; }
                if ($outcome['delivered']) { $delivered++; }
                $this->line("[{$order->system_id}] {$outcome['from']} → {$outcome['status']}"
                    . ($outcome['date_intransit'] ? " · intransit {$outcome['date_intransit']}" : ''));
            }
        }

        $this->info("Done — checked {$updated}, changed {$changed}, in-transit set {$intransit}, delivered {$delivered}, errors {$errors}.");
        return self::SUCCESS;
    }

    /**
     * Orders to poll: have a tracking_id, not cancelled, and whose tracking is
     * not delivered yet (includes never-checked orders). Least-recently-checked
     * first so the queue rotates fairly.
     */
    private function pendingOrders(?string $single, int $limit)
    {
        // Single leftJoin used for BOTH the "not delivered" filter and the
        // staleness ordering — avoids the column-collision a leftJoin + a
        // whereDoesntHave on the same table would cause.
        $q = Order::query()
            ->leftJoin('order_trackings', 'order_trackings.order_id', '=', 'orders.id')
            ->whereNotNull('orders.tracking_id')
            ->where('orders.tracking_id', '!=', '')
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->where(function ($w) {
                $w->whereNull('order_trackings.status')           // never checked
                  ->orWhere('order_trackings.status', '!=', 'delivered');
            })
            ->select('orders.*');

        if ($single !== null && $single !== '') {
            return $q->where(fn ($w) => $w->where('orders.id', (int) $single)->orWhere('orders.system_id', $single))
                ->limit(max($limit, 1))
                ->get();
        }

        return $q
            ->orderByRaw('order_trackings.checked_at IS NULL DESC')   // never-checked first
            ->orderBy('order_trackings.checked_at', 'asc')            // then stalest
            ->limit($limit)
            ->get();
    }

    /** Upsert the order_trackings row from a ShipEngine result. */
    private function applyResult(Order $order, array $res): array
    {
        $events = $res['events'] ?? [];
        $count  = count($events);
        $mapped = $res['status'] ?? 'unknown';
        $delivered = $mapped === 'delivered' || !empty($res['actual_delivery_date']);

        // >= 3 events ⇒ treat as in-transit; date_intransit = 3rd event time.
        $newIntransit = null;
        if ($count >= 3 && !empty($events[2]['occurred_at'])) {
            $newIntransit = $this->parseTime($events[2]['occurred_at']);
        }

        // Effective status: bump weak statuses to in_transit once >= 3 events.
        $status = $delivered ? 'delivered' : $mapped;
        if (!$delivered && $count >= 3 && in_array($mapped, ['accepted', 'pre_shipment', 'unknown'], true)) {
            $status = 'in_transit';
        }

        $existing = $order->tracking;
        $lastEventAt = $count ? $this->parseTime($events[$count - 1]['occurred_at'] ?? null) : null;
        $statusDesc  = $res['status_description'] ?? '';
        $changed = !$existing
            || $existing->status_description !== $statusDesc
            || $existing->status !== $status;

        $payload = [
            'tracking_id'                => $order->tracking_id,
            'carrier_code'               => $res['carrier_code'] ?? 'usps',
            'status'                     => $status,
            'status_code'                => $res['status_code'] ?? null,
            'status_description'         => $statusDesc,
            'carrier_status_description' => $res['carrier_status_description'] ?? null,
            'events_count'               => $count,
            'last_event_at'              => $lastEventAt,
            'checked_at'                 => now(),
            'check_count'                => ($existing->check_count ?? 0) + 1,
            'raw'                        => json_encode($res['raw'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        // date_intransit is set ONCE and never overwritten.
        if ($newIntransit && (!$existing || !$existing->date_intransit)) {
            $payload['date_intransit'] = $newIntransit;
        }
        if ($delivered) {
            $payload['delivered_at'] = !empty($res['actual_delivery_date'])
                ? $this->parseTime($res['actual_delivery_date'])
                : ($existing->delivered_at ?? now());
        }

        OrderTracking::updateOrCreate(['order_id' => $order->id], $payload);

        return [
            'changed'        => $changed,
            'delivered'      => $delivered,
            'intransit'      => (bool) ($payload['date_intransit'] ?? false),
            'status'         => $status,
            'from'           => $existing->status ?? '—',
            'date_intransit' => isset($payload['date_intransit']) ? $payload['date_intransit']->toDateTimeString() : null,
        ];
    }

    private function parseTime($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
