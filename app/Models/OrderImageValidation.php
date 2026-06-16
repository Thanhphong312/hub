<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderImageValidation extends Model
{
    protected $fillable = [
        'order_id', 'checked', 'status', 'image_count', 'failed_count',
        'queued_at', 'checked_at', 'validator_version',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_QUEUED  = 'queued';
    const STATUS_PASS    = 'pass';
    const STATUS_FAIL    = 'fail';

    protected function casts(): array
    {
        return [
            'checked'      => 'boolean',
            'image_count'  => 'integer',
            'failed_count' => 'integer',
            'queued_at'    => 'datetime',
            'checked_at'   => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function failures()
    {
        return $this->hasMany(OrderImageValidationItem::class, 'order_id', 'order_id');
    }
}
