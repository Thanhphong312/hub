<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * status_code was sized for ShipEngine's 2-letter raw codes (IT/DE/…). The
 * cron tracker now also polls Shippo, whose raw tracking_status.status values
 * (PRE_TRANSIT, DELIVERED, …) run up to 11 chars and were overflowing the
 * varchar(8) column ("Data too long for column 'status_code'"). Widen to 32
 * to match the sibling carrier_code/status columns' convention.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_trackings', function (Blueprint $t) {
            $t->string('status_code', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_trackings', function (Blueprint $t) {
            $t->string('status_code', 8)->nullable()->change();
        });
    }
};
