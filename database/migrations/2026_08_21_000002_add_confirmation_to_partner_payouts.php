<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The partner's acknowledgement that the money arrived.
 *
 * Admin recording a payment and the partner having received it are two
 * different facts. Keeping only the first means a transfer that failed, went
 * to the wrong account, or was never actually sent looks identical to one that
 * landed — and nobody finds out until the partner complains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_payouts', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('note');
            $table->string('confirmed_note')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('partner_payouts', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'confirmed_note']);
        });
    }
};
