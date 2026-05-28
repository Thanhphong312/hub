<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accessory extends Model
{
    protected $fillable = ['product_id', 'name', 'stock', 'gangsheet_split'];

    protected $casts = ['gangsheet_split' => 'boolean'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function prices()
    {
        return $this->hasMany(AccessoryPrice::class);
    }
}
