<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariantPrice extends Model
{
    protected $fillable = ['product_variant_id', 'tier_id', 'key', 'price', 'partner_price'];

    const KEYS = ['base_cost', 'label_fee', 'shipping_cost', 'addition_fee', '2nd_fee'];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function tier()
    {
        return $this->belongsTo(Tier::class);
    }
}
