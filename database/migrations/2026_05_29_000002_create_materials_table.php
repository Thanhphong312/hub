<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product materials (chất liệu) the customer picks per order item, like a
 * lightweight accessory: name + free-form info, one flagged default that is
 * auto-selected when adding an item in OrderCreate. No pricing/tier — it's a
 * selectable attribute, not a paid add-on.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('description', 1000)->nullable();   // material info
            $t->boolean('is_default')->default(false);
            $t->tinyInteger('status')->default(1);
            $t->timestamps();

            $t->index('product_id');
        });

        Schema::table('order_items', function (Blueprint $t) {
            $t->foreignId('material_id')->nullable()->after('product_variant_id')
                ->constrained('materials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropForeign(['material_id']);
            $t->dropColumn('material_id');
        });
        Schema::dropIfExists('materials');
    }
};
