<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card-skin gangs are now exported as ONE PDF PER PAGE so the press operator
 * prints a single sheet without splitting a multi-page file. `file_url` keeps
 * pointing at the first page (every existing screen and download link reads
 * it), and this column carries the full page list — mirroring `png_urls`.
 * Null for gangs built as a single multi-page PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            $table->json('pdf_urls')->nullable()->after('png_urls');
        });
    }

    public function down(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            $table->dropColumn('pdf_urls');
        });
    }
};
