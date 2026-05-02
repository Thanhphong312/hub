<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshDuplicateRefIds extends Command
{
    protected $signature = 'orders:refresh-duplicates';
    protected $description = 'Recompute the duplicate_ref_ids snapshot from the orders table';

    public function handle(): int
    {
        $started = microtime(true);

        // TRUNCATE is implicit-commit in MySQL — keep this OUT of a transaction
        // so it doesn't error with "no active transaction" on commit.
        DB::table('duplicate_ref_ids')->delete();
        DB::statement("
            INSERT INTO duplicate_ref_ids (user_id, ref_id, count, computed_at)
            SELECT user_id, ref_id, COUNT(*) AS cnt, NOW()
            FROM orders
            WHERE ref_id IS NOT NULL AND ref_id != ''
            GROUP BY user_id, ref_id
            HAVING COUNT(*) > 1
        ");

        $count = DB::table('duplicate_ref_ids')->count();
        $ms = (int) ((microtime(true) - $started) * 1000);
        $this->info("Refreshed {$count} duplicate ref_ids in {$ms}ms.");
        return 0;
    }
}
