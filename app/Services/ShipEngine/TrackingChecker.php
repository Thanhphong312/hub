<?php

namespace App\Services\ShipEngine;

use App\Models\Order;
use App\Models\OrderTracking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Core tracking-check routine shared by the `tracking:check` artisan command
 * and the app-driven POST /api/tracking/run endpoint. Polls ShipEngine for a
 * batch of orders that have a tracking_id and aren't delivered, and upserts
 * order_trackings.
 *
 * Spec: >= 3 events ⇒ in-transit, date_intransit = 3rd event time (set once);
 * update on status_description change; stop polling once delivered.
 */
class TrackingChecker
{
    /** ShipEngine rate-limit friendly concurrency per pool (~200 req/min plan). */
    private const POOL_SIZE = 8;

    public function __construct(private ShipEngineTrackingService $svc) {}

    public function hasKey(): bool
    {
        return $this->svc->hasKey();
    }

    /**
     * Process up to $limit orders. Returns counts; `checked` < $limit means the
     * pending queue was drained this cycle (caller can stop looping).
     *
     * @return array{checked:int,changed:int,delivered:int,intransit:int,errors:int}
     */
    public function run(int $limit, ?string $single = null): array
    {
        $limit  = max(1, $limit);
        $orders = $this->pendingOrders($single, $limit);

        $out = ['checked' => 0, 'changed' => 0, 'delivered' => 0, 'intransit' => 0, 'errors' => 0];
        if ($orders->isEmpty()) {
            return $out;
        }

        foreach ($orders->chunk(self::POOL_SIZE) as $chunk) {
            $list    = $chunk->values();
            $results = $this->svc->getMultipleTrackingInfo($list->pluck('tracking_id')->all());

            foreach ($list as $i => $order) {
                $res = $results[$i] ?? null;
                if (!$res || empty($res['success'])) {
                    $out['errors']++;
                    Log::warning('tracking:check failed', ['order' => $order->system_id, 'error' => $res['error'] ?? 'no result']);
                    continue;
                }
                $o = $this->applyResult($order, $res);
                $out['checked']++;
                if ($o['changed'])   { $out['changed']++; }
                if ($o['intransit']) { $out['intransit']++; }
                if ($o['delivered']) { $out['delivered']++; }
            }
        }

        return $out;
    }

    /**
     * On-demand check for an explicit set of order ids (UI "Check tracking" on
     * selected orders). Same polling + upsert rules as run(), but driven by the
     * caller's selection. Returns per-order results so the UI can flag which
     * orders changed status.
     *
     * @return array{summary:array,results:array<int,array>}
     */
    public function runForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $orders = Order::with('tracking')
            ->whereIn('id', $ids)
            ->whereNotNull('tracking_id')->where('tracking_id', '!=', '')
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->get();

        $summary = [
            'checked' => 0, 'changed' => 0, 'delivered' => 0, 'intransit' => 0,
            'errors'  => 0,
            'skipped' => max(0, count($ids) - $orders->count()), // no tracking_id / cancelled / not found
        ];
        $results = [];

        foreach ($orders->chunk(self::POOL_SIZE) as $chunk) {
            $list = $chunk->values();
            $res  = $this->svc->getMultipleTrackingInfo($list->pluck('tracking_id')->all());

            foreach ($list as $i => $order) {
                $r   = $res[$i] ?? null;
                $old = $order->tracking?->status;
                if (!$r || empty($r['success'])) {
                    $summary['errors']++;
                    $results[] = ['id' => $order->id, 'system_id' => $order->system_id, 'error' => $r['error'] ?? 'no result'];
                    continue;
                }
                $o = $this->applyResult($order, $r);
                $summary['checked']++;
                if ($o['changed'])   { $summary['changed']++; }
                if ($o['intransit']) { $summary['intransit']++; }
                if ($o['delivered']) { $summary['delivered']++; }
                $results[] = [
                    'id'         => $order->id,
                    'system_id'  => $order->system_id,
                    'old_status' => $old,
                    'status'     => $o['status'],
                    'changed'    => $o['changed'],
                ];
            }
        }

        return ['summary' => $summary, 'results' => $results];
    }

    /**
     * Orders to poll: have a tracking_id, not cancelled, tracking not delivered
     * (incl. never-checked). Least-recently-checked first. A single leftJoin is
     * used for both the filter and the ordering to avoid a column collision.
     */
    private function pendingOrders(?string $single, int $limit)
    {
        $q = Order::query()
            ->leftJoin('order_trackings', 'order_trackings.order_id', '=', 'orders.id')
            ->whereNotNull('orders.tracking_id')
            ->where('orders.tracking_id', '!=', '')
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->where(function ($w) {
                $w->whereNull('order_trackings.status')
                  ->orWhere('order_trackings.status', '!=', 'delivered');
            })
            ->select('orders.*');

        if ($single !== null && $single !== '') {
            return $q->where(fn ($w) => $w->where('orders.id', (int) $single)->orWhere('orders.system_id', $single))
                ->limit(max($limit, 1))
                ->get();
        }

        return $q
            ->orderByRaw('order_trackings.checked_at IS NULL DESC')
            ->orderBy('order_trackings.checked_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /** @return array{changed:bool,delivered:bool,intransit:bool} */
    private function applyResult(Order $order, array $res): array
    {
        $events = $res['events'] ?? [];
        $count  = count($events);
        $mapped = $res['status'] ?? 'unknown';
        $delivered = $mapped === 'delivered' || !empty($res['actual_delivery_date']);

        // >= 3 events ⇒ in-transit; date_intransit = 3rd event time.
        $newIntransit = ($count >= 3 && !empty($events[2]['occurred_at']))
            ? $this->parseTime($events[2]['occurred_at'])
            : null;

        $status = $delivered ? 'delivered' : $mapped;
        if (!$delivered && $count >= 3 && in_array($mapped, ['accepted', 'pre_shipment', 'unknown'], true)) {
            $status = 'in_transit';
        }

        $existing   = $order->tracking;
        $statusDesc = $res['status_description'] ?? '';
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
            'last_event_at'              => $count ? $this->parseTime($events[$count - 1]['occurred_at'] ?? null) : null,
            'checked_at'                 => now(),
            'check_count'                => ($existing->check_count ?? 0) + 1,
            'raw'                        => json_encode($res['raw'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
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
            'changed'   => $changed,
            'delivered' => $delivered,
            'intransit' => (bool) ($payload['date_intransit'] ?? false),
            'status'    => $status,
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
