<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessory_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accessory_id')->constrained('accessories')->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('tiers')->cascadeOnDelete();
            $table->string('style')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unique(['accessory_id', 'tier_id', 'style']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_prices');
    }
};
