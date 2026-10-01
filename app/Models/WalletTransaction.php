<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = ['user_id', 'order_id', 'order_system_id', 'order_ref_id', 'type', 'status', 'method', 'transaction_id', 'amount', 'vnd_amount', 'exchange_rate', 'balance_before', 'balance_after', 'refunded_at', 'note'];

    protected $casts = [
        'refunded_at' => 'datetime',
    ];

    const METHOD_PAYPAL = 'paypal';
    const METHOD_PINGPONG = 'pingpong';
    const METHOD_VNPAY = 'vnpay';
    const METHOD_BANK_TRANSFER = 'bank_transfer';
    const METHODS = [self::METHOD_PAYPAL, self::METHOD_PINGPONG, self::METHOD_VNPAY, self::METHOD_BANK_TRANSFER];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';

    protected static function booted(): void
    {
        // Snapshot the order's ref_id on every new transaction so it survives
        // order deletion (order_id is nulled then). Done here rather than at
        // each of the create() call sites.
        static::creating(function (WalletTransaction $tx) {
            if (empty($tx->order_ref_id) && $tx->order_id) {
                $tx->order_ref_id = Order::whereKey($tx->order_id)->value('ref_id') ?: null;
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
