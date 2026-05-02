<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Duplicate ref_id snapshot is refreshed on-demand by admin via the Orders page
// "Refresh Duplicates" button (POST /api/orders/refresh-duplicates) and inline
// after bulk write paths in OrderController.
