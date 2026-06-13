<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gangsheet extends Model
{
    protected $fillable = [
        'created_by_user_id',
        'filename',
        'file_url',
        'line_id',
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
            'order_ids' => 'array',
            'meta_ids' => 'array',
            'downloaded_at' => 'datetime',
        ];
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
