<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchTracking extends Command
{
    protected $signature = 'orders:fetch-tracking {--limit=50 : max orders processed per run}';
    protected $description = 'Fetch tracking_id for orders that have a shipping_label but no tracking yet (POSTs each label URL to carrier.pressify.us/track).';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $orders = Order::whereNull('tracking_id')
            ->whereNotNull('shipping_label')
            ->where('shipping_label', '!=', '')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No orders pending tracking.');
            return 0;
        }

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($orders as $order) {
            try {
                $response = Http::timeout(15)->post('https://carrier.pressify.us/track', [
                    'url' => $order->shipping_label,
                ]);

                if (!$response->ok()) {
                    $this->warn("[{$order->system_id}] HTTP {$response->status()}");
                    $errors++;
                    continue;
                }

                $data = $response->json();
                // Accept tracking under several common keys.
                $tracking = $data['tracking_id']
                    ?? $data['tracking']
                    ?? $data['trackingNumber']
                    ?? ($data['data']['tracking_id'] ?? null);

                if (!$tracking) {
                    $this->warn("[{$order->system_id}] No tracking in response");
                    $skipped++;
                    continue;
                }

                $order->update(['tracking_id' => trim((string) $tracking)]);
                $this->line("[{$order->system_id}] tracking = {$tracking}");
                $updated++;
            } catch (\Throwable $e) {
                Log::warning('FetchTracking failed', [
                    'order_id' => $order->id,
                    'system_id' => $order->system_id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("[{$order->system_id}] {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("Done — updated {$updated}, skipped {$skipped}, errors {$errors}");
        return 0;
    }
}
