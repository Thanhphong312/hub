<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item result of the card-skin design check: is the artwork the right
     * size, and does it carry a chip cutout of the right size for the chip the
     * customer ordered? Separate from order_image_validations, which only
     * answers "is the URL reachable" — an image can be perfectly reachable and
     * still be the wrong shape.
     *
     * One row per (item, field). The desktop app measures the pixels — only it
     * can decode the image — and posts the verdict here.
     */
    public function up(): void
    {
        Schema::create('order_design_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('field', 32)->default('front');   // meta key measured
            $table->text('url')->nullable();
            $table->string('chip', 16)->nullable();          // smallchip | bigchip | holo | nochip
            $table->string('status', 16)->default('pending'); // ok | fail | error
            $table->string('category', 16)->nullable();      // size | hole | both — để gom nhóm báo cáo
            $table->string('reason')->nullable();            // vì sao trượt (ngắn gọn)
            $table->unsignedInteger('width')->nullable();    // px, đã xoay về ngang
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('hole_w')->nullable();   // px, lỗ khoét lớn nhất
            $table->unsignedInteger('hole_h')->nullable();
            $table->string('checker_version', 8)->nullable();
            $table->timestamp('checked_at')->nullable();
            // Đã bắn Telegram cho lỗi này chưa — để chạy lại cron không spam.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['order_item_id', 'field'], 'odc_item_field_unique');
            $table->index(['status', 'checked_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_design_checks');
    }
};
