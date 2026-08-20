<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a partner gets paid, per rate card rather than per partner.
 *
 * Partner pay used to ride on product_variant_prices.partner_price, keyed by
 * the SELLER tier — which meant every partner on a tier shared one rate, and
 * a partner had to be given a seller tier to be paid at all. Nobody ever did:
 * all partners carry tier_id NULL, every partner_price is empty, and the
 * auto-calculation silently never ran. So there is nothing to migrate.
 *
 * A list is shared on purpose: two workshops on the same terms point at one
 * card and a rate is corrected once. One partner per card degenerates to
 * per-partner pricing, so nothing is lost by the indirection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('partner_price_list_id')->nullable()->after('tier_id')
                ->constrained('partner_price_lists')->nullOnDelete();
        });

        // One row per (list, variant, key). A key with no row is simply not
        // paid — that is how "which fees the partner earns" is expressed, so
        // shipping_cost is left out for a partner who only prints. No separate
        // list of enabled keys to drift out of step with the prices.
        Schema::create('partner_variant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->unique(['partner_price_list_id', 'product_variant_id', 'key'], 'pvp_list_variant_key_unique');
        });

        // Accessories are priced per accessory_prices row, because that is
        // where a style lives — Small Chip and Big Chip are paid differently.
        Schema::create('partner_accessory_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accessory_price_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->unique(['partner_price_list_id', 'accessory_price_id'], 'pap_list_accessory_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_accessory_prices');
        Schema::dropIfExists('partner_variant_prices');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_price_list_id');
        });
        Schema::dropIfExists('partner_price_lists');
    }
};
