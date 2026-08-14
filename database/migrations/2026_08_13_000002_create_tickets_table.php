<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support threads hung off an order.
 *
 * `platform` records who opened the thread and therefore who may read it:
 *   1 = seller  → seller (owner of the order), staff, partner
 *   2 = partner → staff, partner only — the seller must not see partner↔admin
 *                 talk about their order.
 *
 * `status` is what the list screens sort and filter on:
 *   1 open        — raised, nobody has replied yet
 *   2 close       — solved; solved_by/solved_at say who and when
 *   3 new message — someone posted a reply, so it needs a look
 *
 * It is a state of the THREAD, not per-reader: there is no "unread by me".
 * A reply on a closed thread re-opens it to 3 — otherwise an answer to a
 * closed ticket would sit unseen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('subject');
            // Opening message. Replies live in ticket_items.
            $table->text('content');
            $table->tinyInteger('platform')->default(1);
            $table->tinyInteger('status')->default(1);
            $table->foreignId('solved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('solved_at')->nullable();
            $table->timestamps();

            // The two list screens: "tickets on this order" and "open tickets
            // for my platform, newest first".
            $table->index(['order_id', 'created_at']);
            $table->index(['platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
