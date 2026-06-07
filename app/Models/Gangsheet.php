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
    ];

    protected function casts(): array
    {
        return [
            'order_ids' => 'array',
            'meta_ids' => 'array',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // Partner users this gangsheet is assigned to (visible in partner-bullstart).
    public function partners()
    {
        return $this->belongsToMany(User::class, 'gangsheet_partner', 'gangsheet_id', 'user_id')->withTimestamps();
    }
}
