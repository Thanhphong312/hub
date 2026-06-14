<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-seller override of the resend support %. JSON map keyed by reason:
 *   { "third_party": { "base_cost": 100, "2nd_fee": 50, "accessory": 0 }, ... }
 * When a reason has an override here it replaces the global reason.support for
 * that seller; reasons without an entry fall back to the global config.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('resend_support_override')->nullable()->after('resend_free_quota');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('resend_support_override');
        });
    }
};
