<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resend / re-ship orders. A resend is a normal Order (goes through the same
 * convert → gangsheet → ship → tracking pipeline) but links to the original,
 * carries a method (label/stamp) + reason, and its base cost is supported by
 * the workshop per the settings-driven monthly quota.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('resend_of_order_id')->nullable()->after('user_id')
                ->constrained('orders')->nullOnDelete();
            $t->string('resend_method', 16)->nullable()->after('resend_of_order_id'); // label | stamp
            $t->string('resend_reason', 32)->nullable()->after('resend_method');       // config reason key
            $t->decimal('base_support', 10, 2)->default(0)->after('resend_reason');     // base cost the workshop covers
            $t->index('resend_reason');
        });

        // Per-seller monthly free-resend quota (null ⇒ use the global default).
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedInteger('resend_free_quota')->nullable()->after('tier_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('resend_of_order_id');
            $t->dropColumn(['resend_method', 'resend_reason', 'base_support']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('resend_free_quota');
        });
    }
};
