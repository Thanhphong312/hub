<?php

namespace App\Console\Commands;

use App\Models\OrderTracking;
use App\Services\ShipEngine\TrackingChecker;
use Illuminate\Console\Command;

/**
 * Manual / ops runner for the ShipEngine tracking poll. The scheduled cron is
 * APP-DRIVEN (the bullstart desktop app calls POST /api/tracking/run at the
 * configured hours) — this command is for manual runs and debugging. It honours
 * the same enabled + run_hours gate unless --force is given.
 */
class CheckTracking extends Command
{
    protected $signature = 'tracking:check
        {--limit= : per-run batch limit (defaults to settings batch_limit)}
        {--force : ignore the enabled flag and run-hour gate}
        {--order= : check a single order by id or system_id}';

    protected $description = 'Poll ShipEngine for delivery status of orders with a tracking_id (until delivered).';

    public function handle(TrackingChecker $checker): int
    {
        if (!$checker->hasKey()) {
            $this->error('SHIPENGINE_API_KEY is not configured.');
            return self::FAILURE;
        }

        $cfg    = OrderTracking::settings();
        $single = $this->option('order');

        if (!$single && !$this->option('force')) {
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

        $limit = (int) ($this->option('limit') ?: ($cfg['batch_limit'] ?? 100));
        $r = $checker->run($limit, $single);

        $this->info("Done — checked {$r['checked']}, changed {$r['changed']}, "
            . "in-transit {$r['intransit']}, delivered {$r['delivered']}, errors {$r['errors']}.");
        return self::SUCCESS;
    }
}
