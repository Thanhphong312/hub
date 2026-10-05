<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderBackupService;
use Illuminate\Console\Command;

/**
 * One-off / repeatable backfill: push a JSON snapshot of every existing order
 * to Backblaze (order-jsons/) and the order_backups table. Going forward new
 * orders are archived automatically on create/import; this catches up the
 * orders that already existed before the feature shipped.
 *
 *   php artisan orders:backup-all           # all orders
 *   php artisan orders:backup-all --from=12000 --to=13000
 *   php artisan orders:backup-all --missing  # only ones not yet in order_backups
 */
class BackupExistingOrders extends Command
{
    protected $signature = 'orders:backup-all
                            {--from= : Only orders with id >= this}
                            {--to= : Only orders with id <= this}
                            {--missing : Skip orders already present in order_backups}';

    protected $description = 'Archive existing orders to Backblaze (order-jsons/) and the order_backups table';

    public function handle(OrderBackupService $service): int
    {
        $query = Order::query()->orderBy('id');

        if ($this->option('from')) {
            $query->where('id', '>=', (int) $this->option('from'));
        }
        if ($this->option('to')) {
            $query->where('id', '<=', (int) $this->option('to'));
        }
        if ($this->option('missing')) {
            $query->whereNotIn('system_id', function ($q) {
                $q->select('system_id')->from('order_backups');
            });
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No orders to back up.');

            return self::SUCCESS;
        }

        $this->info("Backing up {$total} order(s) to Backblaze + order_backups...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = 0;
        $query->chunkById(200, function ($orders) use ($service, $bar, &$done) {
            foreach ($orders as $order) {
                $service->archive($order, 'backfill');
                $done++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Archived {$done} order(s).");

        return self::SUCCESS;
    }
}
