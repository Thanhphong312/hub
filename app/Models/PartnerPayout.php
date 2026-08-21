<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One payment made to a partner. See the migration for why the settled orders
 * hang off this row rather than a running balance.
 */
class PartnerPayout extends Model
{
    protected $fillable = [
        'user_id', 'amount', 'balance_after', 'orders_count',
        'method', 'transaction_id', 'period_from', 'period_to',
        'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'period_from' => 'date',
            'period_to' => 'date',
        ];
    }

    const METHOD_BANK = 'bank_transfer';

    const METHOD_CASH = 'cash';

    const METHOD_MOMO = 'momo';

    const METHOD_PAYPAL = 'paypal';

    const METHODS = [self::METHOD_BANK, self::METHOD_CASH, self::METHOD_MOMO, self::METHOD_PAYPAL];

    public function partner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Orders this payout settled. */
    public function orders()
    {
        return $this->hasMany(Order::class, 'partner_payout_id');
    }
}
