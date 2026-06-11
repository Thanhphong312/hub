<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTracking extends Model
{
    /** AppSetting key holding this module's run config. */
    public const SETTINGS_KEY = 'tracking_check';

    /** Merged settings (defaults + stored overrides). */
    public static function settings(): array
    {
        $defaults = [
            'enabled'     => true,
            'run_hours'   => [8, 12, 16, 20],  // Vietnam-local hours the cron runs
            'batch_limit' => 100,              // orders polled per run
        ];
        $stored = AppSetting::getJson(self::SETTINGS_KEY, []);
        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    public static function saveSettings(array $values): array
    {
        $merged = array_merge(self::settings(), $values);
        $merged['enabled']     = (bool) $merged['enabled'];
        $merged['run_hours']   = array_values(array_unique(array_map('intval', (array) $merged['run_hours'])));
        sort($merged['run_hours']);
        $merged['batch_limit'] = max(1, (int) $merged['batch_limit']);
        AppSetting::setJson(self::SETTINGS_KEY, $merged);
        return $merged;
    }

    protected $fillable = [
        'order_id', 'tracking_id', 'carrier_code',
        'status', 'status_code', 'status_description', 'carrier_status_description',
        'events_count', 'last_event_at', 'date_intransit', 'delivered_at',
        'checked_at', 'check_count', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'last_event_at'  => 'datetime',
            'date_intransit' => 'datetime',
            'delivered_at'   => 'datetime',
            'checked_at'     => 'datetime',
            'events_count'   => 'integer',
            'check_count'    => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered' || $this->delivered_at !== null;
    }
}
