<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Targeted indexes for the hot `orders` access patterns. InnoDB appends the
     * PK (id) to every secondary index, so each of these also serves the
     * default `ORDER BY id DESC` pagination after the leading equality filter.
     *
     *  - (status, created_at) : admin order list filtered by status + date
     *    range, and the dashboard's status/date aggregate counts.
     *  - (user_id, status)    : a seller's status-tab list (their own orders
     *    filtered by status). The existing (user_id, ref_id) index covers ref
     *    lookups but not status filtering.
     *  - (completed_time)     : the shipped-per-day dashboard
     *    (whereNotNull + range + orderByDesc on completed_time).
     *
     * Deliberately NOT indexed: tracking_id / system_id / ref_id substring
     * search (LIKE '%x%' is non-sargable), shipping_label (now TEXT, only used
     * with NULL/'' checks), and the paid filter (whereColumn comparison).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'orders_status_created_at_index');
            $table->index(['user_id', 'status'], 'orders_user_id_status_index');
            $table->index('completed_time', 'orders_completed_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_at_index');
            $table->dropIndex('orders_user_id_status_index');
            $table->dropIndex('orders_completed_time_index');
        });
    }
};
