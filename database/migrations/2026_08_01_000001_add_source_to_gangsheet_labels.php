<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gangsheet_labels', function (Blueprint $table) {
            // Mirrors gangsheets.source. A label is created alongside its gang,
            // so it belongs to the same channel and must be listed on the
            // matching screen — otherwise FPT and normal labels pile up in one
            // queue with no way to tell them apart.
            $table->string('source', 16)->default('normal')->after('line_id');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('gangsheet_labels', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
