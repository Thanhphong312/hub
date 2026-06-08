<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item "where to buy" link, reused across purchase orders. Lives on the
 * stock target itself (material / accessory_price / supply) so the Đặt hàng
 * page can pre-fill it every time you reorder the same item.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['materials', 'accessory_prices', 'supplies'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('purchase_url', 1000)->nullable()->after('stock');
            });
        }
    }

    public function down(): void
    {
        foreach (['materials', 'accessory_prices', 'supplies'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('purchase_url');
            });
        }
    }
};
