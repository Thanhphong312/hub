<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Durable, off-order record of every order at creation time. The whole
        // point of this table is to SURVIVE the order (and its cascade
        // children) being deleted — so there is deliberately NO foreign key on
        // order_id. Pair it with the JSON pushed to Backblaze (order-jsons/):
        // if an order is ever deleted again, its ref_id/system_id and full
        // snapshot are still recoverable from here and from B2.
        Schema::create('order_backups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('system_id')->unique();
            $table->string('ref_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('source')->nullable();      // web | import_csv | ...
            $table->string('b2_path')->nullable();      // order-jsons/<system_id>.json
            $table->text('b2_url')->nullable();
            $table->boolean('uploaded')->default(false); // B2 PUT succeeded?
            $table->longText('payload')->nullable();     // full JSON snapshot
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_backups');
    }
};
