<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CarrierTrackingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchTracking extends Command
{
    protected $signature = 'orders:fetch-tracking {--limit=50 : max orders processed per run}';
    protected $description = 'Fetch tracking_id for orders that have a shipping_label but no tracking yet (POSTs each label URL to carrier.pressify.us/track).';

    public function handle(CarrierTrackingService $carrier): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $orders = Order::pendingTracking()
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
                // Shared carrier call (Drive-URL rewrite + multi-key parse live in the service).
                $tracking = $carrier->fetch($order->shipping_label)['tracking_id'] ?? null;

                if (!$tracking) {
                    $this->warn("[{$order->system_id}] No tracking in response");
                    $skipped++;
                    continue;
                }

                $order->update(['tracking_id' => $tracking]);
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
