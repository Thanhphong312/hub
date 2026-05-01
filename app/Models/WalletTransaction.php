<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = ['user_id', 'order_id', 'type', 'method', 'amount', 'balance_after', 'note'];

    const METHOD_PAYPAL = 'paypal';
    const METHOD_PINGPONG = 'pingpong';
    const METHODS = [self::METHOD_PAYPAL, self::METHOD_PINGPONG];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
