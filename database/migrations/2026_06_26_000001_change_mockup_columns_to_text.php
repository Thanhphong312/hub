<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // B2 mockup URLs (signed, date-scoped folder paths) routinely exceed the
        // 255-char VARCHAR limit, throwing "Data too long for column
        // 'mockup_front' at row 1" on order create. Widen both to TEXT.
        Schema::table('order_items', function (Blueprint $table) {
            $table->text('mockup_front')->nullable()->change();
            $table->text('mockup_back')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('mockup_front')->nullable()->change();
            $table->string('mockup_back')->nullable()->change();
        });
    }
};
