<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supply extends Model
{
    protected $fillable = [
        'name',
        'code',
        'stock',
        'is_default_label',
        'low_stock_threshold',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_default_label' => 'boolean',
        ];
    }
}
