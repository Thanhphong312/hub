<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR background check: an in-app cron scans not-yet-shipped orders' front_qr
 * design images for a black/dark background in the barcode area (which makes
 * the stamped Code 128 unreadable). qr_bg_status = null (unchecked) | 'ok' |
 * 'black'; qr_bg_checked_at lets the scan skip already-checked orders until a
 * newer _qr meta is generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('qr_bg_status', 16)->nullable()->after('note');
            $table->timestamp('qr_bg_checked_at')->nullable()->after('qr_bg_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['qr_bg_status', 'qr_bg_checked_at']);
        });
    }
};
