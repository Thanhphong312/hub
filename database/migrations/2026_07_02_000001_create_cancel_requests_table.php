<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seller-initiated order cancellation requests. A seller raises one against
     * a not-yet-shipped order; the order moves to status pending_cancel (8) until
     * an admin approves (→ cancelled + wallet refund) or rejects (→ previous
     * status restored). order_id is NOT unique — a rejected order may be
     * re-requested; "only one pending at a time" is enforced in the controller.
     */
    public function up(): void
    {
        Schema::create('cancel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // seller who requested
            $table->string('status')->default('pending')->index();                 // pending | approved | rejected
            $table->text('reason_cancel')->nullable();                             // seller's reason
            $table->tinyInteger('previous_status');                               // order status before pending_cancel — restored on reject
            $table->decimal('refund_percent', 5, 2)->nullable();                  // policy % snapshot at approval
            $table->decimal('refund_amount', 12, 2)->nullable();                  // computed refund credited at approval
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); // admin who decided
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancel_requests');
    }
};
