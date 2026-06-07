<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner role + gangsheet→partner assignment.
 *
 * A "partner" is a user who logs into the partner-bullstart app and only sees
 * the gangsheets an admin has assigned to them (many-to-many via
 * gangsheet_partner). See doc/partner.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent so existing DBs get the role without migrate:fresh.
        Role::firstOrCreate(['slug' => 'partner'], ['name' => 'Partner']);

        Schema::create('gangsheet_partner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gangsheet_id')->constrained('gangsheets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['gangsheet_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gangsheet_partner');
        Role::where('slug', 'partner')->delete();
    }
};
