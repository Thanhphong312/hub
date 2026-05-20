<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Current on-hand stock. Imports increment it, order ships
            // decrement it. Can go negative if ships outpace imports — we
            // don't block the order, we just surface a warning on the
            // inventory page.
            $table->integer('stock')->default(0)->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }
};
