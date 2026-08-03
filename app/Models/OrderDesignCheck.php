<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDesignCheck extends Model
{
    protected $fillable = [
        'order_id', 'order_item_id', 'field', 'url', 'chip',
        'status', 'category', 'reason', 'width', 'height', 'hole_w', 'hole_h',
        'checker_version', 'checked_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
