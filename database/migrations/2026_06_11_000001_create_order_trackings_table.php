<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order delivery tracking state, fetched from ShipEngine by the
 * `tracking:check` cron. Kept in its own table (1-1 with orders) so the orders
 * table stays untouched. The cron keeps polling an order until it is delivered,
 * then stops.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_trackings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $t->string('tracking_id');                 // number that was queried
            $t->string('carrier_code', 32)->default('usps');

            // Normalised app status: pre_shipment, accepted, in_transit,
            // out_for_delivery, delivery_attempted, exception, delivered, unknown.
            $t->string('status', 32)->default('unknown');
            $t->string('status_code', 8)->nullable();  // raw ShipEngine code (IT/DE/…)
            $t->string('status_description')->nullable();
            $t->text('carrier_status_description')->nullable();

            $t->integer('events_count')->default(0);
            $t->timestamp('last_event_at')->nullable();
            // In-transit milestone: set from the 3rd event's time once an order
            // has >= 3 tracking events. Set once, never overwritten.
            $t->timestamp('date_intransit')->nullable();
            $t->timestamp('delivered_at')->nullable();

            $t->timestamp('checked_at')->nullable();    // last poll time
            $t->unsignedInteger('check_count')->default(0);
            $t->longText('raw')->nullable();            // last raw ShipEngine JSON (debug)

            $t->timestamps();

            $t->index('status');
            $t->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_trackings');
    }
};
