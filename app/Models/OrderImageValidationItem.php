<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderImageValidationItem extends Model
{
    protected $fillable = [
        'order_id', 'order_item_id', 'field', 'url', 'reason',
        'http_status', 'content_type',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
        ];
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
