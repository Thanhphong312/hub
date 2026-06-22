<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            // Raw Shippo response captured when a label is bought for this
            // order (seller_ship). Holds the transaction + rate payload:
            // label_url, tracking_number, tracking_url_provider, rate, status…
            // so the full carrier response is retrievable later.
            $table->json('shipping_json')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn('shipping_json');
        });
    }
};
