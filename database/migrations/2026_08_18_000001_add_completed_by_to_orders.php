<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who marked the order shipped, next to the existing `completed_time` that
 * says when. Set on the /qr scan page, which is where a packer actually closes
 * an order out.
 *
 * Nullable and stays null for every order shipped before this column existed —
 * nothing records that after the fact. nullOnDelete so removing a staff account
 * does not take the order's completion timestamp down with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('completed_by')->nullable()->after('completed_time')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
        });
    }
};
