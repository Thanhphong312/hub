<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an accessory gets its own filter chip + filename tag in the
 * Gangsheet Compose tab. Printable accessories (e.g. Scratch Card) need
 * their own gangsheet; physical-only add-ons (e.g. envelope) don't, so
 * admin can hide them from the chip bar.
 *
 * Default true (show). Envelope-type names are flipped to false on migrate
 * as a sensible starting point; admin can adjust per accessory afterwards.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('accessories', function (Blueprint $t) {
            $t->boolean('gangsheet_split')->default(true)->after('name');
        });

        // Seed: physical-only accessories (envelopes) default to no chip.
        DB::table('accessories')
            ->where('name', 'like', '%nvelop%')   // matches Envelop / Envelope / Enverlop
            ->update(['gangsheet_split' => false]);
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $t) {
            $t->dropColumn('gangsheet_split');
        });
    }
};
