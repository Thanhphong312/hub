<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Hours to wait after order creation before auto-pay touches it.
            // 0 = immediate (current behaviour). Sellers pick a preset (1/2/3/4)
            // or a custom value from the Profile page.
            $t->unsignedSmallInteger('auto_pay_delay_hours')->default(0)->after('auto_pay');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('auto_pay_delay_hours');
        });
    }
};
