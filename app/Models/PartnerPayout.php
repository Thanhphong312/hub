<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One payment made to a partner. A plain amount — what is still owed is total
 * earned minus total paid, so no per-order link is needed.
 */
class PartnerPayout extends Model
{
    protected $fillable = [
        'user_id', 'amount', 'balance_after',
        'method', 'transaction_id', 'period_from', 'period_to',
        'note', 'created_by', 'confirmed_at', 'confirmed_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'period_from' => 'date',
            'period_to' => 'date',
            'confirmed_at' => 'datetime',
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
}
