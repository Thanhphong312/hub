<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'style', 'line_id', 'order_type', 'status'];

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function accessories()
    {
        return $this->hasMany(Accessory::class);
    }

    public function materials()
    {
        return $this->hasMany(Material::class);
    }
}
