<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation gangsheet — accumulation "groups".
 *
 * An app-side cron (every hour) assigns pending orders into the currently
 * OPEN group of their bucket; an admin (or the auto-close hooks) later "closes"
 * a group, which builds the PDF and turns it into a gangsheet. See
 * doc/automation_gangsheet.md.
 *
 *  bucket_key = line_id|side_type|accessory_id   (one order → one bucket)
 *  status     = open → closing → generated
 *  seq        = per production-day sequence (resets 08:00 VN), preserved across
 *               a remove-design + regen so the printed run number never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gangsheet_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('seq');                          // run number within production_day
            $table->date('production_day');                          // resets 08:00 VN (see GangsheetGroup::productionDay)
            $table->string('line_id', 16)->nullable();               // bucket part
            $table->string('side_type', 8)->default('one');          // 'one' | 'two'
            $table->unsignedBigInteger('accessory_id')->nullable();  // bucket part (null = none)
            $table->string('bucket_key', 64);                        // "line|side|accessory" precomputed
            $table->string('status', 16)->default('open');           // open | closing | generated
            $table->json('order_ids')->nullable();                   // ordered snapshot of member orders
            $table->foreignId('gangsheet_id')->nullable()->constrained('gangsheets')->nullOnDelete();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closing_at')->nullable();             // when claimed; used to auto-release stale
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['production_day', 'seq']);
            $table->index(['bucket_key', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gangsheet_groups');
    }
};
