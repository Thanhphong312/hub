<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Background tasks are triggered manually from the admin frontend:
//   - POST /api/orders/refresh-duplicates  → "Refresh Duplicates" button
//   - POST /api/orders/fetch-tracking      → "Fetch Tracking" button
// Plus inline refresh of duplicate_ref_ids after bulk writes in OrderController.

// Telegram daily orders report. The command itself no-ops when the
// `daily_cron_enabled` flag is off in app_settings, so the cron entry can
// stay registered all the time and admins toggle it from the Settings UI.
// Requires a system crontab entry like:
//   * * * * * cd /var/www/hub && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('app:telegram-daily-report')->dailyAt('07:00');
