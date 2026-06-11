<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ShipEngine delivery-tracking poll. Scheduled hourly but self-gates inside the
// command on the AppSetting `tracking_check.run_hours` (Vietnam-local) and the
// `enabled` flag, so operators pick the run hours from settings. This is the
// one server-side cron — it requires the host crontab entry:
//   * * * * * cd /path/to/hub && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('tracking:check')->hourly()->withoutOverlapping(30);

// Background tasks are triggered manually from the admin frontend:
//   - POST /api/orders/refresh-duplicates  → "Refresh Duplicates" button
//   - POST /api/orders/fetch-tracking      → "Fetch Tracking" button
// Telegram daily/interval reports run from the desktop app (renderer
// setInterval) — no server-side scheduler. The settings endpoints stay
// available so the app reads/writes config; everything else lives client-side.

// Auto-pay is app-side ONLY: when the seller's Wallet/Profile page is
// open and they have auto_pay=true, the renderer polls POST
// /api/orders/auto-pay/run every minute and the controller settles their
// own oldest-first unpaid orders. No system cron required. The
// orders:auto-pay artisan command is still available for manual ops.
