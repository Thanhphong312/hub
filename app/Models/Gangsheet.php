<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Gangsheet extends Model
{
    protected $fillable = [
        'created_by_user_id',
        'filename',
        'file_url',
        'png_urls',
        'pdf_urls',
        'line_id',
        'source',
        'page_format',
        'first_system_id',
        'last_system_id',
        'orders_count',
        'metas_count',
        'order_ids',
        'meta_ids',
        'downloaded_at',
        'downloaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'png_urls' => 'array',
            'pdf_urls' => 'array',
            'order_ids' => 'array',
            'meta_ids' => 'array',
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * Only gangs that still have at least one order left to ship.
     *
     * order_ids is a JSON column, so there is no relation to constrain and no
     * portable way to unnest it (JSON_TABLE is MySQL 8 only; production runs it
     * but the dev boxes are MariaDB 10.4). Instead both sides are resolved in
     * two small queries and intersected in PHP, then fed back as an id list —
     * the filter has to reach the query builder rather than the page, or
     * pagination would count rows it later drops.
     *
     * Bounded by the number of gangsheets, not orders: a few hundred rows of
     * (id, order_ids) either way.
     */
    public function scopeHasUnshippedOrders(Builder $query): Builder
    {
        $unshipped = Order::where('status', '!=', Order::STATUS_SHIPPED)
            ->pluck('id')->flip();

        $ids = [];
        foreach (self::select('id', 'order_ids')->get() as $g) {
            foreach (($g->order_ids ?? []) as $oid) {
                if ($unshipped->has((int) $oid)) { $ids[] = $g->id; break; }
            }
        }

        return $query->whereIn('gangsheets.id', $ids);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function downloadedBy()
    {
        return $this->belongsTo(User::class, 'downloaded_by_user_id');
    }

    // Partner users this gangsheet is assigned to (visible in partner-bullstart).
    public function partners()
    {
        return $this->belongsToMany(User::class, 'gangsheet_partner', 'gangsheet_id', 'user_id')->withTimestamps();
    }
}
