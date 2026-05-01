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
            // Snapshot of orders.system_id captured at transaction creation time.
            // Survives order deletion (foreign key sets order_id to NULL on delete,
            // but this column preserves the human-readable reference).
            $table->string('order_system_id')->nullable()->after('order_id');
        });

        // Backfill from the note column where possible: notes follow the
        // pattern "Paid order #SYSTEMID" / "Refund order #SYSTEMID".
        $rows = DB::table('wallet_transactions')
            ->whereNull('order_system_id')
            ->whereNotNull('note')
            ->where('note', 'like', '%order #%')
            ->get(['id', 'note']);
        foreach ($rows as $row) {
            if (preg_match('/order\s+#(\S+)/i', $row->note, $m)) {
                DB::table('wallet_transactions')->where('id', $row->id)->update(['order_system_id' => $m[1]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('order_system_id');
        });
    }
};
