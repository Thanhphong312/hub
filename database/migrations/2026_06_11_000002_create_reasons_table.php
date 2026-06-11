<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reprint reasons. `code` is an auto-generated "RSN" + 4 unambiguous chars and
 * is what the reason's QR encodes (rendered client-side). Scanning that QR on
 * the reprint page fills the reason.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('reasons', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code', 16)->unique();   // e.g. RSN7K2A
            $t->string('qr_code')->nullable();   // QR payload (= code)
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reasons');
    }
};
