<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp shipping support. `proof_image` holds the photo the fulfiller
 * uploads when shipping a stamp order (stamp stuck on the envelope),
 * sent back to the seller as evidence since stamp has no tracking.
 *
 * ship_type gains a third value 'stamp' (no column change — it's a
 * free-form varchar already).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('proof_image', 1024)->nullable()->after('convert_label');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('proof_image');
        });
    }
};
