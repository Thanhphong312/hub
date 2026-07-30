<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'mockup_front', 'mockup_back', 'sku_label', 'product_variant_id', 'material_id', 'accessory_item_id', 'price', 'quantity', 'order_type', 'order_type_label'];

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

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    // Legacy single-accessory relation. Kept in sync with the first
    // accessory of accessoryPrices() so existing consumers (Conversion / QR /
    // envelope import) keep working unchanged.
    public function accessoryPrice()
    {
        return $this->belongsTo(AccessoryPrice::class, 'accessory_item_id');
    }

    // Multi-accessory via pivot. Source of truth for pricing and stock.
    public function accessoryPrices()
    {
        return $this->belongsToMany(
            AccessoryPrice::class,
            'order_item_accessories',
            'order_item_id',
            'accessory_price_id'
        )->withTimestamps();
    }

    public function metas()
    {
        return $this->hasMany(OrderItemMeta::class);
    }
}
