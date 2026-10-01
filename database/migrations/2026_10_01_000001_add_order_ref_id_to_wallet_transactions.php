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
            // Snapshot of orders.ref_id, like order_system_id: the FK nulls
            // order_id when the order is deleted, which used to blank the
            // ref_id column of wallet exports (refunds of deleted orders).
            $table->string('order_ref_id')->nullable()->after('order_system_id');
        });

        // Backfill every transaction whose order still exists.
        DB::statement(
            'UPDATE wallet_transactions t JOIN orders o ON o.id = t.order_id
                SET t.order_ref_id = o.ref_id
              WHERE t.order_ref_id IS NULL AND o.ref_id IS NOT NULL AND o.ref_id <> \'\''
        );
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('order_ref_id');
        });
    }
};
