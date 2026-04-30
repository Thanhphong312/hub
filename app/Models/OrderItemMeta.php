<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemMeta extends Model
{
    protected $fillable = ['order_item_id', 'key', 'value', 'production'];

    protected function casts(): array
    {
        return ['production' => 'boolean'];
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
