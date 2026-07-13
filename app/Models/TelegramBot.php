<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named Telegram bot. Two rows: 'admin' (group broadcasts) and 'seller'
 * (user commands + DMs). Credentials only; group destinations live in the
 * `telegram` app_setting.
 */
class TelegramBot extends Model
{
    protected $fillable = ['key', 'label', 'token', 'username', 'webhook_secret'];

    public static function forKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }
}
