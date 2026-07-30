<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            // Public URLs of the per-page PNG exports, in page order. The PDF
            // stays the primary artefact (file_url); PNGs are the same pages
            // saved individually for presses that want images, so this is a
            // list, not a single column. Null = never exported.
            $table->json('png_urls')->nullable()->after('file_url');
        });
    }

    public function down(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            $table->dropColumn('png_urls');
        });
    }
};
