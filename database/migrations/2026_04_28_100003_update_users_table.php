<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->decimal('wallet', 12, 2)->default(0);
            $table->string('api_key')->unique()->nullable();
            $table->tinyInteger('status')->default(1); // 1=active, 0=inactive
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['tier_id']);
            $table->dropColumn(['role_id', 'tier_id', 'wallet', 'api_key', 'status']);
        });
    }
};
