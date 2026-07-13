<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CancelPolicy extends Model
{
    protected $fillable = ['order_status', 'percent', 'enabled'];

    protected function casts(): array
    {
        return [
            'order_status' => 'integer',
            'percent'      => 'decimal:2',
            'enabled'      => 'boolean',
        ];
    }

    /** Policy row for a given order status, or null if none configured. */
    public static function forStatus(int $status): ?self
    {
        return static::where('order_status', $status)->first();
    }
}
