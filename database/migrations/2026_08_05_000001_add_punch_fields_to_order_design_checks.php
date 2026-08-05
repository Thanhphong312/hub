<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hole punching is a three-gate repair: the operator reviews where the hole
     * WILL go, the app punches it and uploads the result, the operator reviews
     * the uploaded file, and only then does the design link get replaced.
     *
     * The punched file therefore has to survive between gates — keeping it only
     * in the renderer would lose the work on a reload and hide it from whoever
     * approves next.
     */
    public function up(): void
    {
        Schema::table('order_design_checks', function (Blueprint $table) {
            $table->text('punched_url')->nullable()->after('url');       // file đã đục, chờ duyệt
            $table->timestamp('punched_at')->nullable()->after('notified_at');
            $table->timestamp('applied_at')->nullable()->after('punched_at'); // đã thay link chưa
        });
    }

    public function down(): void
    {
        Schema::table('order_design_checks', function (Blueprint $table) {
            $table->dropColumn(['punched_url', 'punched_at', 'applied_at']);
        });
    }
};
