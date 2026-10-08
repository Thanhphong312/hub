<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StickerSheetAnalysis extends Model
{
    protected $fillable = [
        'order_item_meta_id', 'order_item_id', 'order_id', 'meta_key', 'source_url', 'design_key',
        'sticker_count', 'auto_count', 'count_edited', 'phash', 'boxes', 'mode',
        'width', 'height', 'template_id', 'match_distance', 'template_manual',
        'analyzed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'boxes' => 'array',
            'count_edited' => 'boolean',
            'template_manual' => 'boolean',
        ];
    }

    public function template()
    {
        return $this->belongsTo(StickerTemplate::class, 'template_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
