<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two named Telegram bots so we can split roles:
     *   - admin  : outbound group broadcasts only (new-order copy, topup, cancel). No webhook.
     *   - seller : the single user-facing bot — /login, /gettracking, per-user DM notifications.
     *              Owns the webhook.
     * Credentials (token/username/webhook_secret) live here; group destinations stay in the
     * `telegram` app_setting. The `admin` row is backfilled from the existing single-bot config
     * so the current @bullstartpodbot keeps working with zero reconfiguration.
     */
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // 'admin' | 'seller'
            $table->string('label')->nullable();
            $table->text('token')->nullable();
            $table->string('username')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->timestamps();
        });

        // Backfill admin from the existing single-bot `telegram` app_setting.
        $raw = DB::table('app_settings')->where('key', 'telegram')->value('value');
        $tg  = $raw ? (json_decode($raw, true) ?: []) : [];

        DB::table('telegram_bots')->insert([
            [
                'key'            => 'admin',
                'label'          => 'Admin (broadcast)',
                'token'          => $tg['bot_token']      ?? '',
                'username'       => $tg['bot_username']    ?? '',
                'webhook_secret' => $tg['webhook_secret']  ?? '',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'key'            => 'seller',
                'label'          => 'Seller (commands + DMs)',
                'token'          => '',
                'username'       => '',
                'webhook_secret' => '',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bots');
    }
};
