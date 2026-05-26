<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock is per-code now (accessory_code), not per accessory_price row.
 * An accessory can have N rows for the same code (one per tier) — they
 * should all share one stock pool. Pool lives on the canonical row
 * (= the lowest id within the same accessory_id + accessory_code group).
 *
 * This migration:
 *  - Sums existing accessory_prices.stock across rows that share the same
 *    (accessory_id, accessory_code).
 *  - Writes the sum onto the canonical row, zeros the rest.
 *
 * Idempotent — running again is a no-op once data is consolidated.
 */
return new class extends Migration {
    public function up(): void
    {
        $groups = DB::table('accessory_prices')
            ->select('accessory_id', 'accessory_code', DB::raw('SUM(stock) AS total'), DB::raw('MIN(id) AS canonical_id'))
            ->groupBy('accessory_id', 'accessory_code')
            ->get();

        foreach ($groups as $g) {
            $sum = (int) $g->total;
            DB::table('accessory_prices')
                ->where('accessory_id', $g->accessory_id)
                ->where(function ($q) use ($g) {
                    if ($g->accessory_code === null) $q->whereNull('accessory_code');
                    else $q->where('accessory_code', $g->accessory_code);
                })
                ->update(['stock' => 0]);
            DB::table('accessory_prices')->where('id', $g->canonical_id)->update(['stock' => $sum]);
        }
    }

    public function down(): void
    {
        // Data-only change; nothing to reverse.
    }
};
