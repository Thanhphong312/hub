<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CancelRequest extends Model
{
    protected $fillable = [
        'order_id', 'user_id', 'status', 'reason_cancel', 'previous_status',
        'refund_percent', 'refund_amount', 'wallet_transaction_id',
        'reviewed_by', 'reviewed_at', 'admin_note',
    ];

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'previous_status' => 'integer',
            'refund_percent'  => 'decimal:2',
            'refund_amount'   => 'decimal:2',
            'reviewed_at'     => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
