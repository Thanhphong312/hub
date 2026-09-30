<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders the seller asked to "push scan" (run tracking the same day).
     * status: 1 = pending, 2 = merged (label bundled into merged_id),
     * 3 = run (cron-tracking saw the order go in transit). `fee` is the push-scan price charged
     * at the time of the push (settings can change later).
     */
    public function up(): void
    {
        Schema::create('push_trackings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->foreignId('merged_id')->nullable()->constrained('merged_labels')->nullOnDelete();
            $table->decimal('fee', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            // Part of total_cost: total_cost = print_cost + shipping_cost + push_scan_fee.
            $table->decimal('push_scan_fee', 10, 2)->default(0)->after('shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('push_scan_fee');
        });
        Schema::dropIfExists('push_trackings');
    }
};
