<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // URL of the SKU label uploaded for this item (FPT orders). Text,
            // like the other URL columns on orders — signed storage URLs blow
            // past 255 chars. Null for normal orders.
            $table->text('sku_label')->nullable()->after('mockup_back');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('sku_label');
        });
    }
};
