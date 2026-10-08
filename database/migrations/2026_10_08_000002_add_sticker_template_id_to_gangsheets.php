<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sticker Sheet gangs hold one _qr each; this is the template ("mẫu")
        // of that _qr's design, so Manage can filter gangs by template.
        Schema::table('gangsheets', function (Blueprint $table) {
            $table->foreignId('sticker_template_id')->nullable()->after('page_format')
                ->constrained('sticker_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gangsheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sticker_template_id');
        });
    }
};
