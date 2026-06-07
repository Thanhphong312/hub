<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockPurchaseOrder extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_APPROVED  = 'approved';
    const STATUS_RECEIVED  = 'received';
    const STATUS_CANCELLED = 'cancelled';

    // Statuses that still count as "đã đặt, chờ hàng về" (not yet in stock).
    const OPEN_STATUSES = ['pending', 'approved'];

    protected $fillable = [
        'product_variant_id', 'accessory_price_id', 'material_id', 'supply_id',
        'quantity', 'unit_price', 'status', 'notes',
        'created_by_user_id', 'approved_by_user_id', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'  => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function productVariant() { return $this->belongsTo(ProductVariant::class); }
    public function accessoryPrice() { return $this->belongsTo(AccessoryPrice::class); }
    public function material()       { return $this->belongsTo(Material::class); }
    public function supply()         { return $this->belongsTo(Supply::class); }
    public function creator()        { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function approver()       { return $this->belongsTo(User::class, 'approved_by_user_id'); }
}
