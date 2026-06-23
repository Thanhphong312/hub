<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->bigInteger('telegram_chat_id')->nullable()->index()->after('api_key');
            $t->datetime('telegram_linked_at')->nullable()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['telegram_chat_id']);
            $t->dropColumn(['telegram_chat_id', 'telegram_linked_at']);
        });
    }
};
