<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Revenue-split to a partner-role user. Admin assigns an order to a
            // partner (via the gangsheet → partner flow) and types the revenue
            // amount that partner earns for it. Locked once the partner marks
            // the gangsheet "đã in" (printed) — see PartnerController::markPrinted.
            $table->foreignId('partner_id')->nullable()->after('paid_cost')
                ->constrained('users')->nullOnDelete();
            $table->decimal('partner_revenue', 10, 2)->nullable()->after('partner_id');
            $table->timestamp('partner_locked_at')->nullable()->after('partner_revenue');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn(['partner_revenue', 'partner_locked_at']);
        });
    }
};
