<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Order channel. 'normal' = the regular flow, 'fpt' = FPT orders,
            // which carry a per-item sku_label and are ganged separately.
            // Every order list scopes on this (default 'normal'), so existing
            // screens keep seeing exactly what they saw before.
            $table->string('source', 16)->default('normal')->after('user_id');
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['source', 'status']);
            $table->dropColumn('source');
        });
    }
};
