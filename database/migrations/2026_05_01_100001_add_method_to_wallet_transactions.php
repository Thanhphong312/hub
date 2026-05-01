<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Nullable in DB so existing rows and non-deposit types (paid/refund)
            // can stay null. Deposit creates enforce paypal/pingpong at the
            // controller validation layer.
            $table->string('method')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
