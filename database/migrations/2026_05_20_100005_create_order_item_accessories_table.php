<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Pivot for multi-accessory items. The legacy single
        // order_items.accessory_item_id stays in place as the "primary"
        // accessory for backward-compat with read paths (Conversion / QR /
        // envelope import all look up a single accessory per item).
        Schema::create('order_item_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accessory_price_id')->constrained('accessory_prices')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['order_item_id', 'accessory_price_id'], 'oia_item_acc_unique');
            $table->index('accessory_price_id');
        });

        // Backfill: every existing single-accessory item becomes one pivot row.
        DB::statement('
            INSERT INTO order_item_accessories (order_item_id, accessory_price_id, created_at, updated_at)
            SELECT id, accessory_item_id, NOW(), NOW()
            FROM order_items
            WHERE accessory_item_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_accessories');
    }
};
