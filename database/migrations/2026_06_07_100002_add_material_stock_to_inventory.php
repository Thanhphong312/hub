<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track stock per material (chất liệu) — an inventory target like variant /
 * accessory / supply. Material stock is its own pool (per material, which
 * belongs to a product), independent of any product_variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->after('is_default');
        });
        Schema::table('stock_imports', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('accessory_id')
                ->constrained('materials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_id');
        });
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }
};
