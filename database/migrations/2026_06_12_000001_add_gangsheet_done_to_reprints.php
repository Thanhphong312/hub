<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark reprints that have already been put on a gangsheet, so the Reprint page
 * can show what's done vs still pending a gang.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('reprints', function (Blueprint $t) {
            $t->boolean('gangsheet_done')->default(false)->after('note');
            $t->timestamp('gangsheet_done_at')->nullable()->after('gangsheet_done');
            $t->index('gangsheet_done');
        });
    }

    public function down(): void
    {
        Schema::table('reprints', function (Blueprint $t) {
            $t->dropIndex(['gangsheet_done']);
            $t->dropColumn(['gangsheet_done', 'gangsheet_done_at']);
        });
    }
};
