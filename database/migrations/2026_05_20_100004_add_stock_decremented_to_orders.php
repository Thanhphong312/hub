<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Tracks whether stock has already been decremented for this
            // order. Used to keep the ship → decrement transition idempotent
            // (re-saving a SHIPPED order doesn't double-deduct).
            $table->boolean('stock_decremented')->default(false)->after('completed_time');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_decremented');
        });
    }
};
