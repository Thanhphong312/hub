<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Snapshot of users.wallet before this transaction was applied.
            // Useful for audits and to derive amount = balance_after - balance_before.
            $table->decimal('balance_before', 12, 2)->nullable()->after('balance_after');

            // External payment-gateway reference (PayPal/PingPong tx ID).
            // Required at the validation layer for deposit; nullable in DB so
            // paid/refund rows can keep it null. Unique allows MySQL's
            // standard "multiple NULLs are allowed" behavior.
            $table->string('transaction_id')->nullable()->unique()->after('method');
        });

        // Backfill balance_before for existing rows: balance_after - amount
        // (works for all types since paid amounts are negative).
        DB::statement('UPDATE wallet_transactions SET balance_before = balance_after - amount WHERE balance_before IS NULL');
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['transaction_id']);
            $table->dropColumn(['balance_before', 'transaction_id']);
        });
    }
};
