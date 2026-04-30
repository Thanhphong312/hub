<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $fillable = [
        'order_id', 'first_name', 'last_name', 'address_1', 'address_2',
        'city', 'state', 'zipcode', 'country',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
