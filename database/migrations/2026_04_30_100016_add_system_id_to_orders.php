<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add system_id as nullable string first so we can backfill.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('system_id')->nullable()->after('user_id');
        });

        // 2. Backfill existing rows. Existing ref_id values were system-generated
        //    (e.g. "BS-XXXX"), so copy them into system_id. For any rows that don't
        //    look like our system format, generate a fresh one.
        $orders = DB::table('orders')->whereNull('system_id')->get(['id', 'ref_id']);
        foreach ($orders as $order) {
            $sid = $order->ref_id ?: ('BS-' . strtoupper(Str::random(8)));
            // Ensure uniqueness in the unlikely case of collisions during backfill.
            while (DB::table('orders')->where('system_id', $sid)->exists()) {
                $sid = 'BS-' . strtoupper(Str::random(8));
            }
            DB::table('orders')->where('id', $order->id)->update(['system_id' => $sid]);
        }

        // 3. Drop the unique constraint on ref_id and make ref_id nullable so it can
        //    hold optional customer references (and free up duplicate values).
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_ref_id_unique');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ref_id')->nullable()->change();
        });

        // 4. Make system_id NOT NULL + UNIQUE now that it is fully populated.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('system_id')->nullable(false)->change();
            $table->unique('system_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['system_id']);
            $table->dropColumn('system_id');
        });
        // Note: cannot fully restore the previous unique-not-null state of ref_id
        // because nullable / duplicate values may now exist. Leaving ref_id nullable.
    }
};
