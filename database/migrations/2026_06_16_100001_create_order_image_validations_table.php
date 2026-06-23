<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Per-order image-URL validation summary (1 row per order). Replaces the
     * client-side localStorage cache (`bs_url_failures`) so validation state is
     * shared across devices/clients and survives a cache clear.
     *
     *   status: pending  → never validated (default for any new/imported order)
     *           queued   → a ValidateOrderImages job has been dispatched
     *           pass     → all image URLs reachable + actually images
     *           fail     → at least one image URL failed (see detail table)
     *
     * `checked` is true only once a validation has actually run (pass|fail).
     */
    public function up(): void
    {
        Schema::create('order_image_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('checked')->default(false)->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('image_count')->default(0);   // total URLs checked
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('queued_at')->nullable();           // when a job was dispatched
            $table->timestamp('checked_at')->nullable();          // when validation last ran
            $table->string('validator_version')->nullable();      // bump to force re-validation
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_image_validations');
    }
};
