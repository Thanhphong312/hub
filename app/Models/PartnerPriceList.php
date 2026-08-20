<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A rate card partners are paid by. Several partners can share one.
 */
class PartnerPriceList extends Model
{
    protected $fillable = ['name', 'note'];

    public function variantPrices()
    {
        return $this->hasMany(PartnerVariantPrice::class);
    }

    public function accessoryPrices()
    {
        return $this->hasMany(PartnerAccessoryPrice::class);
    }

    /** Partners paid by this card. */
    public function partners()
    {
        return $this->hasMany(User::class, 'partner_price_list_id');
    }
}
