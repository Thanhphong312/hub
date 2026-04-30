<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'system_id', 'ref_id', 'status', 'ship_type', 'shipping_label',
        'tracking_id', 'print_cost', 'shipping_cost', 'total_cost',
        'paid_cost', 'completed_time', 'production',
    ];

    protected function casts(): array
    {
        return [
            'completed_time' => 'datetime',
            'production' => 'boolean',
        ];
    }

    const STATUS_NEW_ORDER = 0;
    const STATUS_PROCESSING = 1;
    const STATUS_WRONGSIZE = 2;
    const STATUS_FIXED = 3;
    const STATUS_REPRINT = 4;
    const STATUS_ONHOLD = 5;
    const STATUS_SHIPPED = 6;

    const STATUS_MAP = [
        0 => 'new_order',
        1 => 'processing',
        2 => 'wrongsize',
        3 => 'fixed',
        4 => 'reprint',
        5 => 'onhold',
        6 => 'shipped',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function address()
    {
        return $this->hasOne(Address::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function getStatusNameAttribute(): string
    {
        return self::STATUS_MAP[$this->status] ?? 'unknown';
    }
}
