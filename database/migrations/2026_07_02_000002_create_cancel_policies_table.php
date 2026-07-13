<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refund policy for order cancellation: one row per order status, giving the
     * % of paid_cost refunded to the seller's wallet when a cancel from that
     * status is approved, plus whether cancel is allowed from that status at all.
     * Admin-managed in Bullstart-app → Settings → Cancel Policy.
     */
    public function up(): void
    {
        Schema::create('cancel_policies', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('order_status')->unique(); // Order::STATUS_* (source state)
            $table->decimal('percent', 5, 2)->default(0);  // 0–100 refund % of paid_cost
            $table->boolean('enabled')->default(true);     // false = cancel not allowed from this status
            $table->timestamps();
        });

        // Seed sensible defaults so the feature works immediately. Not-yet-produced
        // states refund fully; in-production states refund half; shipped can't be
        // cancelled. pending_cancel/cancelled are terminal, not source states.
        $now = now();
        DB::table('cancel_policies')->insert([
            ['order_status' => 0, 'percent' => 100, 'enabled' => true,  'created_at' => $now, 'updated_at' => $now], // new_order
            ['order_status' => 1, 'percent' => 50,  'enabled' => true,  'created_at' => $now, 'updated_at' => $now], // producing
            ['order_status' => 2, 'percent' => 50,  'enabled' => true,  'created_at' => $now, 'updated_at' => $now], // wrongsize
            ['order_status' => 3, 'percent' => 50,  'enabled' => true,  'created_at' => $now, 'updated_at' => $now], // fixed
            ['order_status' => 4, 'percent' => 50,  'enabled' => true,  'created_at' => $now, 'updated_at' => $now], // reprint
            ['order_status' => 5, 'percent' => 100, 'enabled' => true,  'created_at' => $now, 'updated_at' => $now], // onhold
            ['order_status' => 6, 'percent' => 0,   'enabled' => false, 'created_at' => $now, 'updated_at' => $now], // shipped
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cancel_policies');
    }
};
