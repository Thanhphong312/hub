<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders can now be placed by package (same shape as stock_imports):
 *   per_package → quantity = package_size * package_count,
 *                 total_cost = unit_price (per pkg) * package_count
 *   per_item    → quantity = quantity, total_cost = unit_price * quantity
 * quantity stays the total units (what poReceive adds to stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_purchase_orders', function (Blueprint $table) {
            $table->string('price_mode', 16)->default('per_item')->after('quantity'); // per_item | per_package
            $table->unsignedInteger('package_size')->nullable()->after('price_mode');
            $table->unsignedInteger('package_count')->nullable()->after('package_size');
            $table->decimal('total_cost', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('stock_purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['price_mode', 'package_size', 'package_count', 'total_cost']);
        });
    }
};
