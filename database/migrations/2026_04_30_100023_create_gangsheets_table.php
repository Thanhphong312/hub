<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gangsheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename');
            $table->string('file_url');
            $table->string('line_id', 16)->nullable();          // dominant line_id (e.g. 'GC')
            $table->string('first_system_id')->nullable();
            $table->string('last_system_id')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('metas_count')->default(0);
            $table->json('order_ids')->nullable();
            $table->json('meta_ids')->nullable();
            $table->timestamps();

            $table->index('line_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gangsheets');
    }
};
