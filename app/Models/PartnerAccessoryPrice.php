<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerAccessoryPrice extends Model
{
    protected $fillable = ['partner_price_list_id', 'accessory_price_id', 'price', 'price_private'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'price_private' => 'decimal:2'];
    }

    public function list()
    {
        return $this->belongsTo(PartnerPriceList::class, 'partner_price_list_id');
    }

    public function accessoryPrice()
    {
        return $this->belongsTo(AccessoryPrice::class, 'accessory_price_id');
    }
}
