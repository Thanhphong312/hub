<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ref_id')->unique();
            $table->tinyInteger('status')->default(0); // 0=new_order,1=processing,2=wrongsize,3=fixed,4=reprint,5=onhold,6=shipped
            $table->string('ship_type')->default('seller_ship'); // seller_ship, tiktok_ship
            $table->string('shipping_label')->nullable();
            $table->string('tracking_id')->nullable();
            $table->decimal('print_cost', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total_cost', 10, 2)->default(0);
            $table->decimal('paid_cost', 10, 2)->default(0);
            $table->timestamp('completed_time')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
