<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

/**
 * Buys shipping labels via Shippo for seller_ship orders.
 *
 * Flow: create a Shipment (from = configured warehouse, to = order address,
 * parcel = configured default) → pick a rate by the configured strategy →
 * buy it via a Transaction → store the full response on addresses.shipping_json
 * and copy label_url/tracking_number onto the order.
 *
 * Token + everything else come from AppSetting `shippo_config`. When test_mode
 * is on the test token is used → Shippo returns a free, non-real test label.
 */
class ShippoService
{
    private const BASE = 'https://api.goshippo.com';

    public function config(): array
    {
        return AppSetting::getJson('shippo_config', []);
    }

    public function isTest(): bool
    {
        return (bool) ($this->config()['test_mode'] ?? true);
    }

    /** The token for the current mode (test vs live). */
    public function token(): ?string
    {
        $c = $this->config();
        return $this->isTest() ? ($c['test_token'] ?? null) : ($c['live_token'] ?? null);
    }

    /**
     * Buy a label for one order. Returns ['ok' => bool, 'message' => ?, ...].
     * Never throws — failures come back as ['ok' => false, 'message' => ...].
     */
    public function buyLabelForOrder(Order $order): array
    {
        $c     = $this->config();
        $token = $this->token();
        if (empty($token)) {
            return ['ok' => false, 'message' => 'Chưa cấu hình Shippo ' . ($this->isTest() ? 'test' : 'live') . ' token (Settings → Shippo)'];
        }
        if ($order->ship_type !== Order::SHIP_SELLER) {
            return ['ok' => false, 'message' => 'Đơn không phải seller_ship'];
        }
        $addr = $order->address;
        if (!$addr) {
            return ['ok' => false, 'message' => 'Đơn chưa có địa chỉ người nhận'];
        }

        $from   = $c['from_address'] ?? [];
        $pcfg   = $c['parcel'] ?? [];
        $headers = ['Authorization' => 'ShippoToken ' . $token, 'Content-Type' => 'application/json'];

        $addressFrom = [
            'name'    => $from['name'] ?? 'BullStart',
            'company' => $from['company'] ?? '',
            'street1' => $from['street1'] ?? '',
            'street2' => $from['street2'] ?? '',
            'city'    => $from['city'] ?? '',
            'state'   => $from['state'] ?? '',
            'zip'     => $from['zip'] ?? '',
            'country' => $from['country'] ?? 'US',
            'phone'   => $from['phone'] ?? '',
            'email'   => $from['email'] ?? '',
        ];
        $addressTo = [
            'name'    => trim(($addr->first_name ?? '') . ' ' . ($addr->last_name ?? '')) ?: 'Customer',
            'street1' => $addr->address_1 ?? '',
            'street2' => $addr->address_2 ?? '',
            'city'    => $addr->city ?? '',
            'state'   => $addr->state ?? '',
            'zip'     => $addr->zipcode ?? '',
            'country' => $addr->country ?? 'US',
            'phone'   => $addr->phone ?? '',
            'email'   => $addr->email ?? '',
        ];
        $parcel = [
            'length'        => (string) ($pcfg['length'] ?? 10),
            'width'         => (string) ($pcfg['width'] ?? 7),
            'height'        => (string) ($pcfg['height'] ?? 1),
            'distance_unit' => $pcfg['distance_unit'] ?? 'in',
            'weight'        => (string) ($pcfg['weight'] ?? 5),
            'mass_unit'     => $pcfg['mass_unit'] ?? 'oz',
        ];

        try {
            $shipResp = Http::withHeaders($headers)->timeout(60)->post(self::BASE . '/shipments/', [
                'address_from' => $addressFrom,
                'address_to'   => $addressTo,
                'parcels'      => [$parcel],
                'async'        => false,
            ]);
            if (!$shipResp->successful()) {
                return ['ok' => false, 'message' => 'Shippo shipment lỗi (HTTP ' . $shipResp->status() . ')', 'detail' => $shipResp->json()];
            }
            $shipment = $shipResp->json();
            $rates = $shipment['rates'] ?? [];
            if (empty($rates)) {
                return ['ok' => false, 'message' => 'Shippo không trả rate nào', 'detail' => $shipment['messages'] ?? null];
            }

            $rate = $this->pickRate($rates, $c['rate'] ?? []);
            if (!$rate || empty($rate['object_id'])) {
                return ['ok' => false, 'message' => 'Không tìm thấy rate khớp cấu hình'];
            }

            $txResp = Http::withHeaders($headers)->timeout(60)->post(self::BASE . '/transactions/', [
                'rate'            => $rate['object_id'],
                'label_file_type' => 'PDF',
                'async'           => false,
            ]);
            if (!$txResp->successful()) {
                return ['ok' => false, 'message' => 'Shippo transaction lỗi (HTTP ' . $txResp->status() . ')', 'detail' => $txResp->json()];
            }
            $tx = $txResp->json();
            if (($tx['status'] ?? '') !== 'SUCCESS') {
                return ['ok' => false, 'message' => 'Mua label thất bại: ' . ($tx['status'] ?? 'unknown'), 'detail' => $tx['messages'] ?? null];
            }

            // Persist the full carrier response + copy key fields to the order.
            $addr->update(['shipping_json' => [
                'test'                  => $this->isTest(),
                'bought_at'             => now()->toIso8601String(),
                'label_url'             => $tx['label_url'] ?? null,
                'tracking_number'       => $tx['tracking_number'] ?? null,
                'tracking_url_provider' => $tx['tracking_url_provider'] ?? null,
                'rate'                  => $rate,
                'transaction'           => $tx,
            ]]);
            $order->update([
                'shipping_label' => $tx['label_url'] ?? $order->shipping_label,
                'tracking_id'    => $tx['tracking_number'] ?? $order->tracking_id,
            ]);

            return [
                'ok'              => true,
                'test'            => $this->isTest(),
                'label_url'       => $tx['label_url'] ?? null,
                'tracking_number' => $tx['tracking_number'] ?? null,
                'rate'            => [
                    'amount'       => $rate['amount'] ?? null,
                    'currency'     => $rate['currency'] ?? null,
                    'provider'     => $rate['provider'] ?? null,
                    'servicelevel' => $rate['servicelevel']['name'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Shippo exception: ' . $e->getMessage()];
        }
    }

    /** Pick a rate per the configured strategy: cheapest | carrier | service. */
    private function pickRate(array $rates, array $cfg): ?array
    {
        $strategy = $cfg['strategy'] ?? 'cheapest';
        $carrier  = strtolower(trim((string) ($cfg['carrier'] ?? '')));
        $service  = strtolower(trim((string) ($cfg['servicelevel'] ?? '')));

        $filtered = $rates;
        if ($strategy === 'service' && $service !== '') {
            $filtered = array_values(array_filter($rates, fn ($r) => strtolower($r['servicelevel']['token'] ?? '') === $service));
        } elseif ($strategy === 'carrier' && $carrier !== '') {
            $filtered = array_values(array_filter($rates, fn ($r) => strtolower($r['provider'] ?? '') === $carrier));
        }
        if (empty($filtered)) {
            $filtered = $rates; // fall back to all rates if the filter matched none
        }

        usort($filtered, fn ($a, $b) => ((float) ($a['amount'] ?? 1e9)) <=> ((float) ($b['amount'] ?? 1e9)));
        return $filtered[0] ?? null;
    }
}
