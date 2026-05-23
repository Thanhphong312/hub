<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consumable supplies tracked by inventory (label paper, packaging tape,
 * toner, etc.). Each row has its own stock pool — imported via the same
 * Inventory page as product variants / accessories.
 *
 * Exactly 1 row at a time should carry `is_default_label = true`:
 * Order::decrementStockForShipment subtracts 1 from that row whenever an
 * order ships (regardless of how many items the order has — 1 order = 1
 * label paper).
 *
 * Seeds a starter "Label paper" row so the feature works out of the box.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplies', function (Blueprint $t) {
            $t->id();
            $t->string('name');                       // "Label paper"
            $t->string('code', 64)->nullable();       // free-form sku/code for CSV import
            $t->integer('stock')->default(0);
            $t->boolean('is_default_label')->default(false);
            $t->integer('low_stock_threshold')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();

            $t->index('is_default_label');
        });

        // Imports can now target a supply (in addition to variant / accessory).
        Schema::table('stock_imports', function (Blueprint $t) {
            $t->foreignId('supply_id')->nullable()->after('accessory_id')->constrained()->nullOnDelete();
            $t->index('supply_id');
        });

        // Seed default row so the inventory page has something to show.
        // Skip if already exists (re-run safe).
        $exists = DB::table('supplies')->where('is_default_label', true)->exists();
        if (!$exists) {
            DB::table('supplies')->insert([
                'name' => 'Label paper',
                'code' => 'LABEL',
                'stock' => 0,
                'is_default_label' => true,
                'low_stock_threshold' => 100,
                'notes' => 'Default label paper supply — 1 ship = 1 trừ.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_imports', function (Blueprint $t) {
            $t->dropForeign(['supply_id']);
            $t->dropIndex(['supply_id']);
            $t->dropColumn('supply_id');
        });
        Schema::dropIfExists('supplies');
    }
};
