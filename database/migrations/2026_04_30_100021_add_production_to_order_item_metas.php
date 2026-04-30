<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_metas', function (Blueprint $table) {
            $table->boolean('production')->default(false)->after('value');
            $table->index(['key', 'production']);
        });
    }

    public function down(): void
    {
        Schema::table('order_item_metas', function (Blueprint $table) {
            $table->dropIndex(['key', 'production']);
            $table->dropColumn('production');
        });
    }
};
