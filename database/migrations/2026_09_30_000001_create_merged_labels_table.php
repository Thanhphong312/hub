<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Push-scan merged labels: one PDF that bundles the labels of several
     * push_trackings rows. status: 1 = not sent yet, 2 = sent.
     */
    public function up(): void
    {
        Schema::create('merged_labels', function (Blueprint $table) {
            $table->id();
            $table->text('label_url')->nullable();
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merged_labels');
    }
};
