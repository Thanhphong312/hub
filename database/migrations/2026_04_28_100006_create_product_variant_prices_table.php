<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('tiers')->cascadeOnDelete();
            $table->string('key')->default('base_cost'); // base_cost, label_fee, shipping_cost
            $table->decimal('price', 10, 2);
            $table->unique(['product_variant_id', 'tier_id', 'key']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_prices');
    }
};
