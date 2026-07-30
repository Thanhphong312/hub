<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            // Which order channel this gang was built from — mirrors
            // orders.source. FPT gangs are composed and listed on their own
            // screen, so they must never mix with the normal ones.
            $table->string('source', 16)->default('normal')->after('line_id');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
