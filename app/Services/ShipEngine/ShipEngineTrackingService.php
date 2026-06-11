<?php

namespace App\Services\ShipEngine;

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
        $this->apiKey = (string) config('shipengine.api_key', '');
        $this->defaultCarrier = (string) config('shipengine.carrier_code', 'usps');
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

        $apiKey  = $this->apiKey;
        $baseUrl = $this->baseUrl;

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($r) => $pool
                ->withHeaders(['API-Key' => $apiKey, 'Content-Type' => 'application/json'])
                ->timeout(20)
                ->get($baseUrl . 'tracking', [
                    'carrier_code'    => $r['carrier'],
                    'tracking_number' => $r['number'],
                ]),
            $clean
        ));

        $results = [];
        foreach ($clean as $i => $req) {
            $response = $responses[$i];
            try {
                if ($response instanceof \Throwable) {
                    throw $response;
                }
                $data = $response->json();
                if (!$response->successful()) {
                    $results[] = [
                        'success'         => false,
                        'tracking_number' => $req['number'],
                        'error'           => $data['errors'][0]['message'] ?? $data['message'] ?? ('API error ' . $response->status()),
                        'code'            => $data['errors'][0]['error_code'] ?? 'SE_ERROR',
                    ];
                } else {
                    $results[] = $this->parseResponse($req['number'], $req['carrier'], $data);
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'success'         => false,
                    'tracking_number' => $req['number'],
                    'error'           => $e->getMessage(),
                    'code'            => 'API_ERROR',
                ];
            }
        }

        return $results;
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
