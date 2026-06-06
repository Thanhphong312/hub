<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the page format a gangsheet PDF was built at ('original' = 10×7,
 * 'letter' = 11×8.5, 'a4'). Lets the Manage tab split gangs into Default / A4.
 * Null = legacy gangs made before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            $table->string('page_format', 16)->nullable()->after('line_id');
            $table->index('page_format');
        });
    }

    public function down(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            $table->dropIndex(['page_format']);
            $table->dropColumn('page_format');
        });
    }
};
