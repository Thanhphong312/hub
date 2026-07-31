<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GangsheetLabel extends Model
{
    protected $fillable = [
        'gangsheet_id',
        'created_by_user_id',
        'name',
        'line_id',
        'source',
        'order_ids',
        'merged_from_label_ids',
        'file_url',
        'orders_count',
        'total_items_qty',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'order_ids' => 'array',
            'merged_from_label_ids' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function gangsheet()
    {
        return $this->belongsTo(Gangsheet::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The short scan code printed on / encoded in the barcode. "GSL" prefix
     * tells the /qr scan page to route to /gs/{code} instead of /qr/{code}.
     */
    public function getScanCodeAttribute(): string
    {
        return 'GSL' . $this->id;
    }
}
