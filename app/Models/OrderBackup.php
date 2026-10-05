<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A snapshot of an order taken at creation/import time, kept independently of
 * the `orders` table (no FK) so it outlives the order if it is ever deleted.
 * Mirrors the JSON uploaded to Backblaze under order-jsons/<system_id>.json.
 */
class OrderBackup extends Model
{
    protected $fillable = [
        'order_id',
        'system_id',
        'ref_id',
        'user_id',
        'source',
        'b2_path',
        'b2_url',
        'uploaded',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload'  => 'array',
            'uploaded' => 'boolean',
        ];
    }
}
