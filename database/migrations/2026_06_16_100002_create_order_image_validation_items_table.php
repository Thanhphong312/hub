<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * One row per FAILED image URL — the normalized detail behind a `fail`
     * summary. Mirrors the shape the client used to keep in localStorage
     * (`{ [itemId]: { [field]: reason } }`) but queryable, e.g. "all orders
     * failing on Drive permissions". Rows are replaced wholesale each time an
     * order is re-validated. Only failures are stored (a passing URL leaves no
     * row); `order_image_validations.image_count` records the total checked.
     */
    public function up(): void
    {
        Schema::create('order_image_validation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->cascadeOnDelete();
            // 'mockup_front' | 'mockup_back' | 'meta:<key>' — matches the client field keys.
            $table->string('field');
            $table->text('url');
            $table->string('reason'); // 'not found', 'no permission / not shared publicly', ...
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_image_validation_items');
    }
};
