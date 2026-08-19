<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference photo for one add-on choice, shown on the /qr scan page so the
 * packer can see what "Small Chip" or "HOLO Broken Glass" actually looks like
 * instead of matching it from the name.
 *
 * On accessory_prices rather than accessories, because that is the row a style
 * lives on — an order item points at a price row, and the /qr page already
 * shows its style. Same level as purchase_url, which is per-style for the same
 * reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessory_prices', function (Blueprint $table) {
            $table->string('image_des', 1000)->nullable()->after('purchase_url');
        });
    }

    public function down(): void
    {
        Schema::table('accessory_prices', function (Blueprint $table) {
            $table->dropColumn('image_des');
        });
    }
};
