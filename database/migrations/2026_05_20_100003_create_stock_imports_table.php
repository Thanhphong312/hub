<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_imports', function (Blueprint $table) {
            $table->id();
            // Exactly one of these is set per import row.
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('accessory_price_id')->nullable()->constrained('accessory_prices')->nullOnDelete();
            // Total items added to stock. Per-item mode: this is the
            // requested quantity. Per-package mode: package_size * package_count.
            $table->unsignedInteger('quantity');
            // 'per_item' (price per single unit) or 'per_package'
            // (price for a whole package containing package_size units).
            $table->string('price_mode', 16)->default('per_item');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->unsignedInteger('package_size')->nullable();
            $table->unsignedInteger('package_count')->nullable();
            // Computed at store-time for fast reporting.
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->useCurrent();
            $table->timestamps();

            $table->index('product_variant_id');
            $table->index('accessory_price_id');
            $table->index('imported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_imports');
    }
};
