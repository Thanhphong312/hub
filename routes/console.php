<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Background tasks are triggered manually from the admin frontend:
//   - POST /api/orders/refresh-duplicates  → "Refresh Duplicates" button
//   - POST /api/orders/fetch-tracking      → "Fetch Tracking" button
// Telegram daily/interval reports run from the desktop app (renderer
// setInterval) — no server-side scheduler. The settings endpoints stay
// available so the app reads/writes config; everything else lives client-side.
