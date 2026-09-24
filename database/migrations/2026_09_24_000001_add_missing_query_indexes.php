<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for filters that had none:
     *
     *  - orders.ref_id                     : admin "paste a ref_id list" lookup
     *    (whereIn without user_id — orders_user_ref_idx can't serve it).
     *  - gangsheet_labels.line_id          : Gangsheet Label list line filter.
     *  - wallet_transactions (user_id, type): per-user type filter / total_spent.
     *  - wallet_transactions.created_at    : wallet list date range.
     *  - order_design_checks.checked_at    : list sorted by checked_at with no
     *    status filter ((status, checked_at) only helps when status is set).
     */
    private array $indexes = [
        ['orders', ['ref_id'], 'orders_ref_id_index'],
        ['gangsheet_labels', ['line_id'], 'gangsheet_labels_line_id_index'],
        ['wallet_transactions', ['user_id', 'type'], 'wallet_transactions_user_id_type_index'],
        ['wallet_transactions', ['created_at'], 'wallet_transactions_created_at_index'],
        ['order_design_checks', ['checked_at'], 'order_design_checks_checked_at_index'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, , $name]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
