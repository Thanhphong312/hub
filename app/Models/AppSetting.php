<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Free-form key/value bag. `value` is JSON-encoded; callers should use
 * AppSetting::getJson() / setJson() rather than touching the raw column.
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getJson(string $key, $default = null)
    {
        $row = static::find($key);
        if (!$row || $row->value === null) return $default;
        $decoded = json_decode($row->value, true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $default : $decoded;
    }

    public static function setJson(string $key, $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
    }
}
