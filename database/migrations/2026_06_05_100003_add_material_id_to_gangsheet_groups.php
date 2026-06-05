<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material (chất liệu) becomes part of the gangsheet bucket: a different paper
 * stock can't share a sheet. Handled dynamically by material_id (like
 * accessory) so new materials need no code change. For an order with multiple
 * items, the FIRST item's material decides the bucket. bucket_key encodes
 * line|side|accessory|material.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gangsheet_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('material_id')->nullable()->after('accessory_id');
        });
    }

    public function down(): void
    {
        Schema::table('gangsheet_groups', function (Blueprint $table) {
            $table->dropColumn('material_id');
        });
    }
};
