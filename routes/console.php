<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ShipEngine delivery-tracking is APP-DRIVEN (no server cron), matching the
// auto-pay / Telegram pattern above: the bullstart desktop app polls and, at
// the configured run hours, calls POST /api/tracking/run which does the
// ShipEngine lookup + order_trackings update. The `tracking:check` artisan
// command stays available for manual/ops runs.

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
