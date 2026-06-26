<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * URL-holding columns on `orders` overflow their VARCHAR limits when a
     * seller pastes a long marketplace/CDN URL (e.g. Walmart image links),
     * throwing "Data too long for column 'shipping_label' at row 1". Widen the
     * whole family to TEXT. None of these columns are indexed, so TEXT is safe.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('shipping_label')->nullable()->change();
            $table->text('tracking_id')->nullable()->change();
            $table->text('convert_label')->nullable()->change();
            $table->text('proof_image')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_label')->nullable()->change();
            $table->string('tracking_id')->nullable()->change();
            $table->string('convert_label', 1024)->nullable()->change();
            $table->string('proof_image', 1024)->nullable()->change();
        });
    }
};
