<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Per-chat mute switch toggled by the Telegram /recievemessage command.
            // Defaults to true so existing linked users keep receiving notifications.
            $t->boolean('telegram_notifications_enabled')->default(true)->after('telegram_linked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('telegram_notifications_enabled');
        });
    }
};
