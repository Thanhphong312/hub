<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Accumulation group for the automation-gangsheet flow. See
 * doc/automation_gangsheet.md. Membership of orders is authoritative via
 * orders.gangsheet_group_id; `order_ids` here is an ordered snapshot kept in
 * sync for display/counts.
 */
class GangsheetGroup extends Model
{
    const STATUS_OPEN      = 'open';
    const STATUS_CLOSING   = 'closing';
    const STATUS_GENERATED = 'generated';

    // Production fixed to Vietnam time; the production "day" rolls over at 08:00.
    const TZ = 'Asia/Ho_Chi_Minh';
    const DAY_START_HOUR = 8;

    protected $fillable = [
        'seq', 'production_day', 'line_id', 'side_type', 'accessory_id', 'material_id',
        'bucket_key', 'status', 'order_ids', 'gangsheet_id',
        'claimed_by_user_id', 'closing_at', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'order_ids'    => 'array',
            'production_day' => 'date',
            'closing_at'   => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * The production day (Y-m-d) a moment belongs to. The day starts at 08:00
     * VN — anything 00:00–07:59 VN still counts as the previous production day.
     */
    public static function productionDay(?Carbon $t = null): string
    {
        $t = ($t ?? Carbon::now())->copy()->setTimezone(self::TZ)->subHours(self::DAY_START_HOUR);
        return $t->toDateString();
    }

    public static function bucketKey(?string $lineId, string $sideType, ?int $accessoryId, ?int $materialId): string
    {
        return ($lineId ?? '') . '|' . $sideType . '|' . ((int) $accessoryId) . '|' . ((int) $materialId);
    }

    public function gangsheet()
    {
        return $this->belongsTo(Gangsheet::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function claimer()
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }
}
