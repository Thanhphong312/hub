<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add price_private to partner price tables so each key can carry a separate
 * rate for sellers on the Private tier. The existing `price` column becomes
 * the Public-tier rate; price_private is NULL when the partner earns the same
 * regardless of the seller's tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_variant_prices', function (Blueprint $table) {
            $table->decimal('price_private', 10, 2)->nullable()->after('price');
        });

        Schema::table('partner_accessory_prices', function (Blueprint $table) {
            $table->decimal('price_private', 10, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('partner_variant_prices', function (Blueprint $table) {
            $table->dropColumn('price_private');
        });

        Schema::table('partner_accessory_prices', function (Blueprint $table) {
            $table->dropColumn('price_private');
        });
    }
};
