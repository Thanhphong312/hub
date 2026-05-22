<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move accessory stock from per-tier (accessory_prices.stock) to per-accessory
 * (accessories.stock). Pricing stays tier-scoped on accessory_prices; only
 * physical stock is unified.
 *
 * Idempotent backfill — re-running on a partially-migrated DB still works.
 * accessory_prices.stock column is kept as deprecated for safety; a later
 * migration can drop it once nothing reads from it.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) Add unified stock on the accessory itself.
        if (!Schema::hasColumn('accessories', 'stock')) {
            Schema::table('accessories', function (Blueprint $t) {
                $t->integer('stock')->default(0)->after('name');
            });
        }

        // 2) Backfill: sum across tier rows for each accessory.
        DB::statement('
            UPDATE accessories a
            JOIN (
                SELECT accessory_id, COALESCE(SUM(stock), 0) AS total_stock
                FROM accessory_prices
                GROUP BY accessory_id
            ) ap ON ap.accessory_id = a.id
            SET a.stock = ap.total_stock
        ');

        // 3) Add stock_imports.accessory_id so future imports target the
        //    accessory directly. Legacy rows keep accessory_price_id for
        //    audit; we backfill accessory_id from the linked accessory_price.
        if (!Schema::hasColumn('stock_imports', 'accessory_id')) {
            Schema::table('stock_imports', function (Blueprint $t) {
                $t->foreignId('accessory_id')->nullable()->after('accessory_price_id')->constrained()->nullOnDelete();
                $t->index('accessory_id');
            });
        }

        DB::statement('
            UPDATE stock_imports si
            JOIN accessory_prices ap ON ap.id = si.accessory_price_id
            SET si.accessory_id = ap.accessory_id
            WHERE si.accessory_price_id IS NOT NULL AND si.accessory_id IS NULL
        ');
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_imports', 'accessory_id')) {
            Schema::table('stock_imports', function (Blueprint $t) {
                $t->dropForeign(['accessory_id']);
                $t->dropIndex(['accessory_id']);
                $t->dropColumn('accessory_id');
            });
        }
        if (Schema::hasColumn('accessories', 'stock')) {
            Schema::table('accessories', function (Blueprint $t) {
                $t->dropColumn('stock');
            });
        }
    }
};
