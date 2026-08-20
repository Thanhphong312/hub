<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerVariantPrice extends Model
{
    protected $fillable = ['partner_price_list_id', 'product_variant_id', 'key', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function list()
    {
        return $this->belongsTo(PartnerPriceList::class, 'partner_price_list_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
