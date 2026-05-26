<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Wired into routes/console.php via Schedule::command('app:telegram-daily-report')->dailyAt('07:00').
 * Skips silently if daily_cron_enabled is off or required settings missing.
 */
class TelegramDailyReport extends Command
{
    protected $signature = 'app:telegram-daily-report';
    protected $description = 'Send the daily orders summary to the configured Telegram chat';

    public function handle(TelegramService $telegram): int
    {
        $cfg = TelegramService::settings();
        if (empty($cfg['daily_cron_enabled'])) {
            $this->info('Telegram daily cron disabled — skipping.');
            return self::SUCCESS;
        }
        if (empty($cfg['bot_token']) || empty($cfg['default_chat_id'])) {
            $this->warn('Missing bot_token or default_chat_id — skipping.');
            return self::SUCCESS;
        }
        try {
            $result = $telegram->sendOrdersReport(null, null, null);
            $this->info("Sent telegram report id={$result['message_id']} ({$result['from']} → {$result['to']})");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Telegram daily report failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
