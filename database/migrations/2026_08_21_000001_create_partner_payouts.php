<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money paid OUT to partners, the mirror of wallet_transactions.
 *
 * wallet_transactions records what sellers pay us; this records what we pay
 * partners for the printing. The two never mix — a seller's wallet balance and
 * a partner's earnings are different sides of the same order.
 *
 * A payout carries the orders it settles (orders.partner_payout_id), so "đã
 * trả" and "còn nợ" are derived from the orders themselves rather than from a
 * running balance nobody can audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            // What was still owed after this payout, stamped at the time. Kept
            // even though it is derivable, so an old receipt still reads the
            // way it did on the day it was issued.
            $table->decimal('balance_after', 12, 2)->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->string('method', 32)->nullable();        // bank_transfer, cash, momo…
            $table->string('transaction_id')->nullable();    // mã chuyển khoản / biên lai
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Which payout settled this order. Null = the partner has earned on
            // it but has not been paid yet.
            $table->foreignId('partner_payout_id')->nullable()->after('partner_revenue')
                ->constrained('partner_payouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_payout_id');
        });
        Schema::dropIfExists('partner_payouts');
    }
};
