<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the "đã làm / downloaded" tick on the gangsheet itself (was
 * per-machine localStorage), so the mark is shared across machines + users.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('gangsheets', function (Blueprint $t) {
            $t->timestamp('downloaded_at')->nullable()->after('meta_ids');
            $t->foreignId('downloaded_by_user_id')->nullable()->after('downloaded_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gangsheets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('downloaded_by_user_id');
            $t->dropColumn('downloaded_at');
        });
    }
};
