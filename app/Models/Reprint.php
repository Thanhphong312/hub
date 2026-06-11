<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reprint extends Model
{
    protected $fillable = ['system_id', 'order_id', 'user_id', 'reason_id', 'note', 'gangsheet_done', 'gangsheet_done_at'];

    protected function casts(): array
    {
        return [
            'gangsheet_done'    => 'boolean',
            'gangsheet_done_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
