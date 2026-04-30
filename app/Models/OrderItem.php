<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'mockup_front', 'mockup_back', 'product_variant_id', 'accessory_item_id', 'price', 'quantity', 'order_type'];

    const TYPE_GREETING_CARD = 0;
    const TYPE_PASS_SLEEVE = 1;

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function accessoryPrice()
    {
        return $this->belongsTo(AccessoryPrice::class, 'accessory_item_id');
    }

    public function metas()
    {
        return $this->hasMany(OrderItemMeta::class);
    }
}
