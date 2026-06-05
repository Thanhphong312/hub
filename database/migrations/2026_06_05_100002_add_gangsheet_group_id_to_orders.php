<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative membership pointer: which OPEN/CLOSING/GENERATED automation
 * group an order currently belongs to. Lets pendingOrders() exclude orders
 * already in a group (no double-ganging vs the manual Compose tab) without
 * scanning JSON arrays. Set on assign, nulled on remove-design / group delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('gangsheet_group_id')->nullable()->after('production')
                ->constrained('gangsheet_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gangsheet_group_id');
        });
    }
};
