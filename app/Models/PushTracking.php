<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushTracking extends Model
{
    const STATUS_PENDING = 1;
    const STATUS_MERGED = 2;
    const STATUS_RUN     = 3;

    /** AppSetting key holding the push-scan price ({"price": 0.5}). */
    public const SETTINGS_KEY = 'push_scan_config';

    protected $fillable = ['order_id', 'status', 'merged_id', 'fee'];

    public static function config(): array
    {
        $c = AppSetting::getJson(self::SETTINGS_KEY, []);
        return ['price' => round((float) ($c['price'] ?? 0), 2)];
    }

    public static function price(): float
    {
        return self::config()['price'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function mergedLabel()
    {
        return $this->belongsTo(MergedLabel::class, 'merged_id');
    }
}
