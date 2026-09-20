<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;

/**
 * Resolves a tracking number from a shipping-label URL via the self-hosted
 * label.laviglobal.us barcode-scan API (decodes the carrier barcode/DataMatrix
 * printed on the label — see D:\Laviglobal\note\huong-dan-api-labelscan.md).
 * This is the single server-side implementation shared by the order endpoint,
 * the console command, and the Telegram /gettracking job — so the Drive-URL
 * rewrite + response parsing live in exactly one place.
 *
 * Credentials (base_url/username/password) are admin-editable app_settings
 * (key "labelscan") rather than committed to code — see TrackingController's
 * labelscanKey()/updateLabelscanKey().
 */
class CarrierTrackingService
{
    // There's only one labelscan deployment; default it so saving just
    // username/password (the base_url field is easy to leave blank behind
    // its own placeholder text) doesn't silently leave the service
    // unconfigured.
    private const DEFAULT_BASE_URL = 'https://label.laviglobal.us';

    public function hasCredentials(): bool
    {
        $cfg = $this->config();

        return $cfg['base_url'] !== '' && $cfg['username'] !== '' && $cfg['password'] !== '';
    }

    /** @return array{base_url: string, username: string, password: string} */
    public function config(): array
    {
        $cfg = AppSetting::getJson('labelscan', []);
        $cfg = is_array($cfg) ? $cfg : [];

        return [
            'base_url' => rtrim((string) ($cfg['base_url'] ?? '') ?: self::DEFAULT_BASE_URL, '/'),
            'username' => (string) ($cfg['username'] ?? ''),
            'password' => (string) ($cfg['password'] ?? ''),
        ];
    }

    /**
     * Fetch tracking for a single shipping-label URL.
     *
     * @return array{tracking_id: ?string, carrier: ?string, dest_postal_code: ?string, raw: mixed}
     *
     * @throws \RuntimeException on a hard HTTP failure or missing config. The
     *         message carries the upstream status so callers can mirror the
     *         app's "Carrier {status}" log lines.
     */
    public function fetch(string $shippingLabel): array
    {
        $cfg = $this->config();
        if (! $this->hasCredentials()) {
            throw new \RuntimeException('Chưa cấu hình labelscan (base_url/username/password) — vào Settings để nhập.');
        }

        // Drive /view URLs serve an HTML preview wrapper rather than the file
        // itself, so labelscan can't parse them as PDF. Convert any Drive
        // share URL into the direct-download form before forwarding.
        $labelUrl = $shippingLabel;
        if (preg_match('#(?:/file/d/|[?&]id=)([a-zA-Z0-9_-]+)#', $labelUrl, $m)) {
            $labelUrl = "https://drive.google.com/uc?export=download&id={$m[1]}";
        }

        $response = Http::withBasicAuth($cfg['username'], $cfg['password'])
            ->timeout(40)
            ->post("{$cfg['base_url']}/scan", ['url' => $labelUrl]);

        if ($response->status() === 401) {
            throw new \RuntimeException('Labelscan 401 — sai username/password.');
        }
        if (! $response->ok()) {
            throw new \RuntimeException('Labelscan responded HTTP ' . $response->status() . ': ' . $response->body());
        }

        $data = $response->json() ?? [];
        $tracking = $data['tracking_numbers'][0] ?? null;

        return [
            'tracking_id' => $tracking !== null ? trim((string) $tracking) : null,
            'carrier' => $data['carrier'] ?? ($data['carriers'][0] ?? null),
            'dest_postal_code' => $data['barcodes'][0]['tracking']['dest_postal_code'] ?? null,
            'raw' => $data,
        ];
    }
}
