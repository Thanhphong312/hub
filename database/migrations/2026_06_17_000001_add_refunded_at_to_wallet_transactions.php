<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Set when a `paid` transaction has been refunded (a separate
            // `refund` row was created crediting the wallet back). Guards
            // against double-refunding the same paid transaction and lets the
            // UI disable the checkbox of an already-refunded row.
            $table->timestamp('refunded_at')->nullable()->after('balance_after');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('refunded_at');
        });
    }
};
