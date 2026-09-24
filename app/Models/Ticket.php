<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'order_id',
        'created_by',
        'subject',
        'content',
        'attachments',
        'platform',
        'status',
        'solved_by',
        'solved_at',
    ];

    protected function casts(): array
    {
        return [
            'platform'    => 'integer',
            'status'      => 'integer',
            'solved_at'   => 'datetime',
            'attachments' => 'array',
        ];
    }

    // Who opened the thread — and, with it, who may read it. See scopeVisibleTo.
    const PLATFORM_SELLER  = 1;
    const PLATFORM_PARTNER = 2;
    const PLATFORMS = [self::PLATFORM_SELLER, self::PLATFORM_PARTNER];

    // Thread state. Not per-reader: "new message" means the thread has a reply
    // waiting on it, for everyone who can see it.
    const STATUS_OPEN        = 1;
    const STATUS_CLOSE       = 2;
    const STATUS_NEW_MESSAGE = 3;
    const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSE, self::STATUS_NEW_MESSAGE];
    const STATUS_MAP = [
        self::STATUS_OPEN        => 'open',
        self::STATUS_CLOSE       => 'close',
        self::STATUS_NEW_MESSAGE => 'new_message',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function solver()
    {
        return $this->belongsTo(User::class, 'solved_by');
    }

    public function items()
    {
        return $this->hasMany(TicketItem::class)->orderBy('id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_MAP[$this->status] ?? 'open';
    }

    /**
     * Restrict a query to what `$user` is allowed to see. Every read path must
     * go through this — it is the only place the platform rules live.
     *
     *   staff (admin/support) → everything
     *   seller  → platform 1 only, and only on orders they own. Platform 2 is
     *             partner↔staff talk about their order and stays hidden.
     *   partner → both platforms, but only on orders assigned to them
     *             (orders.partner_id), so one partner never reads another's.
     *   anyone else → nothing
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user) return $query->whereRaw('1 = 0');

        if ($user->hasRole('admin') || $user->hasRole('support')) {
            return $query;
        }

        if ($user->hasRole('seller')) {
            return $query
                ->where('platform', self::PLATFORM_SELLER)
                ->whereHas('order', fn ($q) => $q->where('user_id', $user->id));
        }

        if ($user->hasRole('partner')) {
            return $query->whereHas('order', fn ($q) => $q->where('partner_id', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * The platform a NEW ticket from `$user` gets. Staff answer inside existing
     * threads rather than opening their own, so they are not given a platform
     * of their own — they must say which one they are posting as.
     */
    public static function platformFor(User $user): ?int
    {
        if ($user->hasRole('seller'))  return self::PLATFORM_SELLER;
        if ($user->hasRole('partner')) return self::PLATFORM_PARTNER;

        return null;
    }
}
