<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Resolves a tracking number from a shipping-label URL by POSTing it to
 * carrier.pressify.us. This is the single server-side implementation shared by
 * the order endpoint, the console command, and the Telegram /gettracking job —
 * so the Drive-URL rewrite + response parsing live in exactly one place.
 */
class CarrierTrackingService
{
    private const ENDPOINT = 'https://carrier.pressify.us/track';

    /**
     * Fetch tracking for a single shipping-label URL.
     *
     * @return array{tracking_id: ?string, carrier: ?string, raw: mixed}
     *
     * @throws \RuntimeException on a hard HTTP failure. The message carries the
     *         upstream status so callers can mirror the app's "Carrier {status}"
     *         log lines.
     */
    public function fetch(string $shippingLabel): array
    {
        // Drive /view URLs serve an HTML preview wrapper rather than the file
        // itself, so carrier.pressify.us can't parse them as PDF. Convert any
        // Drive share URL into the direct-download form before forwarding.
        $labelUrl = $shippingLabel;
        if (preg_match('#(?:/file/d/|[?&]id=)([a-zA-Z0-9_-]+)#', $labelUrl, $m)) {
            $labelUrl = "https://drive.google.com/uc?export=download&id={$m[1]}";
        }

        $response = Http::timeout(15)->post(self::ENDPOINT, ['url' => $labelUrl]);

        if (!$response->ok()) {
            throw new \RuntimeException('Carrier responded HTTP ' . $response->status());
        }

        $data = $response->json();
        // Accept tracking under several common keys.
        $tracking = $data['tracking_id']
            ?? $data['tracking']
            ?? $data['trackingNumber']
            ?? ($data['data']['tracking_id'] ?? null);

        return [
            'tracking_id' => $tracking !== null ? trim((string) $tracking) : null,
            'carrier'     => $data['carrier'] ?? null,
            'raw'         => $data,
        ];
    }
}
