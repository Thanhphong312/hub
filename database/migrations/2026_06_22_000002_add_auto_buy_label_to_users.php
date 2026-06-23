<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Seller opt-in: when true, the app-side cron auto-buys Shippo labels
            // for this seller's seller_ship orders that have no label yet —
            // mirrors the auto_pay flag.
            $table->boolean('auto_buy_label')->default(false)->after('auto_pay_delay_hours');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_buy_label');
        });
    }
};
