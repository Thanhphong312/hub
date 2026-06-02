<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-seller auto-pay toggle. When true, the orders:auto-pay scheduled
 * command settles the seller's oldest-first unpaid orders against their
 * wallet balance every minute.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('auto_pay')->default(false)->after('wallet');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('auto_pay');
        });
    }
};
