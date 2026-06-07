<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders — quantity ordered from a supplier that is "on the way".
 * Lifecycle: pending → approved → received (received adds to stock). A target
 * is exactly one of variant / accessory_price / material / supply (same shape
 * as stock_imports). The forecast subtracts pending+approved (not yet received)
 * qty as "đã đặt chờ về".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('accessory_price_id')->nullable()->constrained('accessory_prices')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained('supplies')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('status', 16)->default('pending');   // pending | approved | received | cancelled
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_purchase_orders');
    }
};
