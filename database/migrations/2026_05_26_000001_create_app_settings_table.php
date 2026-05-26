<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row-per-key store for app-wide settings that don't fit anywhere
 * else: invoice payment info (PingPong account, VNPay QR URL), business
 * profile overrides, etc. Values are JSON-encoded strings so callers can
 * stash arbitrary shapes without per-feature schema changes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $t) {
            $t->string('key', 120)->primary();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
