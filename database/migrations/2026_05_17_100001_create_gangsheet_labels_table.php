<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gangsheet_labels', function (Blueprint $table) {
            $table->id();
            // Parallel to the gangsheet that triggered creation. Nullable on
            // delete so deleting the underlying gangsheet leaves the label
            // record intact (operators may still need to reconcile shipped
            // orders against it).
            $table->foreignId('gangsheet_id')->nullable()->constrained('gangsheets')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Mirrors the source gangsheet's filename so operators can match
            // the printed _qr sheet against this label record at a glance.
            $table->string('name');
            $table->string('line_id', 16)->nullable();
            $table->json('order_ids')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            // Sum of order_items.quantity across every order in the group —
            // surfaced as the headline number on the scan page.
            $table->unsignedInteger('total_items_qty')->default(0);
            // 'pending' until an operator hits Complete all on the scan page;
            // then 'completed' with a timestamp.
            $table->string('status', 16)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gangsheet_labels');
    }
};
