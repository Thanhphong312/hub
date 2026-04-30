<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_prices', function (Blueprint $table) {
            $table->string('accessory_code')->nullable()->after('style');
            $table->index(['accessory_code', 'tier_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accessory_prices', function (Blueprint $table) {
            $table->dropIndex(['accessory_code', 'tier_id']);
            $table->dropColumn('accessory_code');
        });
    }
};
