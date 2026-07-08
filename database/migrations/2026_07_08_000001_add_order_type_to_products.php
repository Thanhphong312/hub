<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Product's order type (free-form label from the configurable list
            // in AppSetting `order_types`). Drives the "Type" column shown per
            // order item — replaces the legacy 2-value order_items.order_type.
            $table->string('order_type', 64)->nullable()->after('line_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('order_type');
        });
    }
};
