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
            // 'pending' for deposits awaiting admin approval; 'approved' once
            // admin has verified the gateway transaction (or for paid/refund
            // rows which are always approved at creation time).
            $table->string('status', 20)->default('pending')->after('type');
        });

        // Backfill: existing rows are historical and always treated as approved.
        DB::statement("UPDATE wallet_transactions SET status = 'approved'");
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
