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
}
