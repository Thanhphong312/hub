<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessoryPrice extends Model
{
    protected $fillable = ['accessory_id', 'tier_id', 'style', 'accessory_code', 'price', 'stock', 'purchase_url'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function accessory()
    {
        return $this->belongsTo(Accessory::class);
    }

    public function tier()
    {
        return $this->belongsTo(Tier::class);
    }
}
