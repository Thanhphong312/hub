<?php

namespace App\Services\ShipEngine;

use App\Models\AppSetting;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over ShipEngine's GET /v1/tracking endpoint. Adapted from the
 * check-tracking-usps guide, but returns STRUCTURED events (with occurred_at)
 * so callers can derive milestones like date_intransit (3rd event time).
 */
class ShipEngineTrackingService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.shipengine.com/v1/';
    protected string $defaultCarrier;

    public function __construct()
    {
        // App-entered key (AppSetting 'shipengine', set from the desktop app)
        // takes precedence over the server .env so operators can configure
        // ShipEngine without shell access. Empty app value → fall back to env.
        $override = AppSetting::getJson('shipengine', []);
        $override = is_array($override) ? $override : [];

        $this->apiKey = (string) (($override['api_key'] ?? '') ?: config('shipengine.api_key', ''));
        $this->defaultCarrier = (string) (($override['carrier_code'] ?? '') ?: config('shipengine.carrier_code', 'usps'));
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== '';
    }

    /** Track a single number. */
    public function getTrackingInfo(string $trackingNumber, ?string $carrierCode = null): array
    {
        $results = $this->getMultipleTrackingInfo([$trackingNumber], $carrierCode);
        return $results[0] ?? [
            'success'         => false,
            'tracking_number' => $trackingNumber,
            'error'           => 'Unable to retrieve tracking information',
        ];
    }

    /**
     * Track many numbers concurrently. Returns one result per input, in order.
     */
    public function getMultipleTrackingInfo(array $trackingNumbers, ?string $carrierCode = null): array
    {
        $clean = [];
        foreach ($trackingNumbers as $n) {
            $n = trim((string) $n);
            if ($n !== '') {
                $clean[] = ['number' => $n, 'carrier' => $carrierCode ?: $this->defaultCarrier];
            }
        }
        if (empty($clean)) {
            return [];
        }

        $results = array_fill(0, count($clean), null);
        $pending = array_keys($clean);            // indices still awaiting a result

        // Retry ONLY the entries that hit ShipEngine's 429 rate limit (~200/min),
        // honoring Retry-After with exponential backoff, so a big batch
        // self-throttles instead of failing en masse.
        for ($round = 0; $round < 5 && $pending; $round++) {
            $batch     = array_values($pending);
            $responses = $this->poolTracking(array_map(fn ($i) => $clean[$i], $batch));

            $retry      = [];
            $retryAfter = 0;
            foreach ($batch as $k => $i) {
                $response = $responses[$k];
                if (!($response instanceof \Throwable) && $response->status() === 429) {
                    $retry[]    = $i;
                    $retryAfter = max($retryAfter, (int) $response->header('Retry-After'));
                    continue;
                }
                $results[$i] = $this->normalizeResponse($clean[$i], $response);
            }

            $pending = $retry;
            if ($pending) {
                $waitMs = $retryAfter > 0 ? $retryAfter * 1000 : min(8000, 500 * (2 ** $round));
                usleep($waitMs * 1000);
            }
        }

        // Still rate-limited after all rounds → explicit error result.
        foreach ($pending as $i) {
            $results[$i] = [
                'success'         => false,
                'tracking_number' => $clean[$i]['number'],
                'error'           => 'ShipEngine rate limit (429)',
                'code'            => 'RATE_LIMIT',
            ];
        }

        return $results;
    }

    /** Fire one concurrent pool of tracking lookups; responses align to $reqs. */
    private function poolTracking(array $reqs): array
    {
        $apiKey  = $this->apiKey;
        $baseUrl = $this->baseUrl;

        return Http::pool(fn (Pool $pool) => array_map(
            fn ($r) => $pool
                ->withHeaders(['API-Key' => $apiKey, 'Content-Type' => 'application/json'])
                ->timeout(20)
                ->get($baseUrl . 'tracking', [
                    'carrier_code'    => $r['carrier'],
                    'tracking_number' => $r['number'],
                ]),
            $reqs
        ));
    }

    /** Turn one pooled response (or thrown error) into a normalised result. */
    private function normalizeResponse(array $req, $response): array
    {
        try {
            if ($response instanceof \Throwable) {
                throw $response;
            }
            $data = $response->json();
            if (!$response->successful()) {
                return [
                    'success'         => false,
                    'tracking_number' => $req['number'],
                    'error'           => $data['errors'][0]['message'] ?? $data['message'] ?? ('API error ' . $response->status()),
                    'code'            => $data['errors'][0]['error_code'] ?? 'SE_ERROR',
                ];
            }
            return $this->parseResponse($req['number'], $req['carrier'], $data);
        } catch (\Throwable $e) {
            return [
                'success'         => false,
                'tracking_number' => $req['number'],
                'error'           => $e->getMessage(),
                'code'            => 'API_ERROR',
            ];
        }
    }

    /** Normalise a raw ShipEngine response. Events are sorted oldest-first. */
    protected function parseResponse(string $trackingNumber, string $carrier, array $data): array
    {
        $events = [];
        foreach (($data['events'] ?? []) as $event) {
            $occurredAt = $event['occurred_at'] ?? null;
            $events[] = [
                'occurred_at'  => $occurredAt,
                'description'  => $event['description'] ?? '',
                'status_code'  => $event['status_code'] ?? null,
                'city'         => $event['city_locality'] ?? '',
                'state'        => $event['state_province'] ?? '',
                'postal_code'  => $event['postal_code'] ?? '',
            ];
        }
        // Oldest-first so events[2] is reliably the 3rd scan chronologically.
        usort($events, fn ($a, $b) => strcmp((string) $a['occurred_at'], (string) $b['occurred_at']));

        $statusCode = $data['status_code'] ?? 'UN';

        return [
            'success'                    => true,
            'tracking_number'            => $trackingNumber,
            'carrier_code'               => $carrier,
            'status_code'                => $statusCode,
            'status'                     => $this->mapStatusCode($statusCode),
            'status_description'         => $data['status_description'] ?? '',
            'carrier_status_description' => $data['carrier_status_description'] ?? null,
            'actual_delivery_date'       => $data['actual_delivery_date'] ?? null,
            'events'                     => $events,
            'raw'                        => $data,
        ];
    }

    protected function mapStatusCode(?string $code): string
    {
        return [
            'DE' => 'delivered',
            'IT' => 'in_transit',
            'AC' => 'accepted',
            'AT' => 'delivery_attempted',
            'EX' => 'exception',
            'NY' => 'pre_shipment',
            'DY' => 'out_for_delivery',
        ][$code] ?? 'unknown';
    }
}
