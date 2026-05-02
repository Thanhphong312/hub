<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Speeds up "is_duplicate_ref" detection in OrderController::index
            // (queries grouped by user_id + ref_id) and any future lookups by
            // a seller's ref_id space.
            $table->index(['user_id', 'ref_id'], 'orders_user_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_ref_idx');
        });
    }
};
