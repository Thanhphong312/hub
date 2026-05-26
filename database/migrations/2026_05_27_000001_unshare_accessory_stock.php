<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reverse the share_accessory_stock change. Each accessory_prices row
 * (= one accessory_code / choice like envelope White vs Red) now owns
 * its own stock again, because operators want to track inventory per
 * choice rather than as a shared pool.
 *
 * Backfill strategy:
 *  - Move accessories.stock onto the FIRST (lowest-id) accessory_prices
 *    row for that accessory. Other rows reset to 0.
 *  - Operator rebalances by importing per-code stock via CSV after deploy.
 *  - accessories.stock column kept (deprecated) so no data is lost if we
 *    need to revisit.
 *
 * Re-attribute stock_imports.accessory_id rows to the first
 * accessory_price row of each accessory so the imports/history view stays
 * non-empty.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) For each accessory with a `stock` value, move it onto the first
        //    accessory_prices row (lowest id) and zero the rest.
        $accessories = DB::table('accessories')->select('id', 'stock')->get();
        foreach ($accessories as $a) {
            // Zero everything first so a re-run is idempotent.
            DB::table('accessory_prices')->where('accessory_id', $a->id)->update(['stock' => 0]);

            $first = DB::table('accessory_prices')
                ->where('accessory_id', $a->id)
                ->orderBy('id')
                ->first();
            if ($first && $a->stock) {
                DB::table('accessory_prices')->where('id', $first->id)->update(['stock' => $a->stock]);
            }
        }

        // 2) Re-attribute stock_imports rows that only have accessory_id
        //    (post-migration imports) onto the first accessory_price of that
        //    accessory so they show up under the right code in history.
        DB::statement('
            UPDATE stock_imports si
            JOIN (
                SELECT accessory_id, MIN(id) AS first_price_id
                FROM accessory_prices
                GROUP BY accessory_id
            ) ap ON ap.accessory_id = si.accessory_id
            SET si.accessory_price_id = ap.first_price_id
            WHERE si.accessory_id IS NOT NULL AND si.accessory_price_id IS NULL
        ');
    }

    public function down(): void
    {
        // No structural change here — just a data backfill. Down() leaves
        // the per-code stock in place; operators can re-sync if needed.
    }
};
