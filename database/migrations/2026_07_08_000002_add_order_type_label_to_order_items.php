<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Snapshot of the chosen product's order_type (products.order_type)
            // at the time the item is created/updated. The legacy int order_type
            // (0/1) is kept but no longer drives the displayed "Type".
            $table->string('order_type_label', 64)->nullable()->after('order_type');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('order_type_label');
        });
    }
};
