<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reprint requests. Created by scanning an order's system_id barcode; a reason
 * is attached by scanning a reason QR (or picking one). `user_id` is whoever
 * created it — each user sees their own reprints (admins see all).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('reprints', function (Blueprint $t) {
            $t->id();
            $t->string('system_id')->index();
            // Resolved order when the system_id matches one (kept nullable so a
            // reprint can still be logged for an unknown/typo system_id).
            $t->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('reason_id')->nullable()->constrained('reasons')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index('user_id');
            $t->index('reason_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reprints');
    }
};
