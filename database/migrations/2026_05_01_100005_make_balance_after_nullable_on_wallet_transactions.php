<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Pending deposits are created before the wallet is credited, so
            // balance_after is unknown until admin approves. Allow NULL.
            $table->decimal('balance_after', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('balance_after', 12, 2)->nullable(false)->change();
        });
    }
};
