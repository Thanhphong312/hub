<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = ['user_id', 'order_id', 'order_system_id', 'type', 'status', 'method', 'transaction_id', 'amount', 'vnd_amount', 'exchange_rate', 'balance_before', 'balance_after', 'note'];

    const METHOD_PAYPAL = 'paypal';
    const METHOD_PINGPONG = 'pingpong';
    const METHOD_VNPAY = 'vnpay';
    const METHOD_BANK_TRANSFER = 'bank_transfer';
    const METHODS = [self::METHOD_PAYPAL, self::METHOD_PINGPONG, self::METHOD_VNPAY, self::METHOD_BANK_TRANSFER];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
