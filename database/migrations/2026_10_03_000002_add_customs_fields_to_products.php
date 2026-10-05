<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-product customs info, used to build the Shippo customs
        // declaration for international shipments. All optional — ShippoService
        // falls back to the shippo_config.customs defaults when a field is blank.
        Schema::table('products', function (Blueprint $table) {
            $table->string('customs_description')->nullable()->after('order_type'); // English goods description
            $table->string('hs_tariff_number')->nullable()->after('customs_description'); // HS / tariff code
            $table->string('origin_country', 2)->nullable()->after('hs_tariff_number'); // country of manufacture (ISO-2)
            $table->decimal('customs_value', 10, 2)->nullable()->after('origin_country'); // declared unit value override
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['customs_description', 'hs_tariff_number', 'origin_country', 'customs_value']);
        });
    }
};
