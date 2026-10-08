<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A known sticker sheet design ("mẫu"). New sheets are matched against
        // these by fingerprint, so the same design across orders lands on one
        // template.
        Schema::create('sticker_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sticker_count')->default(0);
            $table->char('phash', 64);                 // 256-bit dHash, hex
            $table->text('sample_url')->nullable();    // design it was saved from
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        // One analysed source design (front/back… meta) of a Sticker Sheet item.
        // The analysis runs in the desktop app; the hub stores and matches.
        Schema::create('sticker_sheet_analyses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_item_meta_id')->unique();
            $table->unsignedBigInteger('order_item_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('meta_key', 32);
            $table->text('source_url');                // re-analysed when it changes
            // Normalised link (Drive file id, else the URL): every meta with
            // the same key is the same file, so it is analysed once and the
            // result copied to the rest.
            $table->string('design_key', 191)->index();
            $table->unsignedSmallInteger('sticker_count');
            $table->unsignedSmallInteger('auto_count'); // what the detector said
            $table->boolean('count_edited')->default(false);
            $table->char('phash', 64)->index();
            $table->json('boxes')->nullable();          // [[x,y,w,h] fractions]
            $table->string('mode', 16)->nullable();     // outline | transparent
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('sticker_templates')->nullOnDelete();
            $table->unsignedSmallInteger('match_distance')->nullable();
            $table->boolean('template_manual')->default(false); // set by hand, keep on rematch
            $table->unsignedBigInteger('analyzed_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sticker_sheet_analyses');
        Schema::dropIfExists('sticker_templates');
    }
};
