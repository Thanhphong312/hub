<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_ref_ids', function (Blueprint $table) {
            // Cached snapshot of (user_id, ref_id) pairs that have more than one
            // matching order. Refreshed by `php artisan orders:refresh-duplicates`
            // (scheduled) or after bulk write paths. OrderController::index
            // joins this so list views don't recompute on every page load.
            $table->unsignedBigInteger('user_id');
            $table->string('ref_id', 255);
            $table->unsignedInteger('count')->default(2);
            $table->timestamp('computed_at')->useCurrent();
            $table->primary(['user_id', 'ref_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_ref_ids');
    }
};
