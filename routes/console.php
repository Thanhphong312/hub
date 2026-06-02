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
// Telegram daily/interval reports run from the desktop app (renderer
// setInterval) — no server-side scheduler. The settings endpoints stay
// available so the app reads/writes config; everything else lives client-side.

// Auto-pay: every minute, settle oldest-first unpaid orders for users
// who toggled users.auto_pay on. Requires `php artisan schedule:run`
// to be triggered every minute via system cron, e.g.:
//   * * * * * cd /home/webapps/devhub && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('orders:auto-pay')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
