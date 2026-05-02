<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Paper finish (e.g. Gloss, Matte, Linen). Stored alongside color/size
            // as a third visible variant attribute.
            $table->string('paper_type')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('paper_type');
        });
    }
};
