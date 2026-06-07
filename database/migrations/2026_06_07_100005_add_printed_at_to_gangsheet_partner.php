<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-partner "printed" mark on an assigned gangsheet. Each partner ticks when
 * they finish printing their copy (partner-bullstart). Null = not printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gangsheet_partner', function (Blueprint $table) {
            $table->timestamp('printed_at')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('gangsheet_partner', function (Blueprint $table) {
            $table->dropColumn('printed_at');
        });
    }
};
