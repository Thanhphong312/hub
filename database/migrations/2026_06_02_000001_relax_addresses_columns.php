<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imported orders + partial address edits (e.g. only typing address_1) were
 * failing INSERTs because addresses required first/last/city/zipcode. Make
 * every contact + locality column nullable and add phone/email so importers
 * can attach whatever fields the upstream system actually has.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $t) {
            $t->string('first_name')->nullable()->change();
            $t->string('last_name')->nullable()->change();
            $t->string('address_1')->nullable()->change();
            $t->string('city')->nullable()->change();
            $t->string('zipcode')->nullable()->change();
            $t->string('phone', 64)->nullable()->after('country');
            $t->string('email', 191)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $t) {
            $t->dropColumn(['phone', 'email']);
            $t->string('first_name')->nullable(false)->change();
            $t->string('last_name')->nullable(false)->change();
            $t->string('address_1')->nullable(false)->change();
            $t->string('city')->nullable(false)->change();
            $t->string('zipcode')->nullable(false)->change();
        });
    }
};
