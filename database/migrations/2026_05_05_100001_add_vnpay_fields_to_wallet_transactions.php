<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // VND paid + the VND→USD rate at the moment of payment, so audits
            // can reconcile bank statements without depending on later rate
            // changes.
            $table->decimal('vnd_amount', 14, 2)->nullable()->after('amount');
            $table->decimal('exchange_rate', 12, 2)->nullable()->after('vnd_amount');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn(['vnd_amount', 'exchange_rate']);
        });
    }
};
