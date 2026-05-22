<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockImport extends Model
{
    protected $fillable = [
        'product_variant_id',
        // accessory_price_id kept for legacy audit on rows imported before
        // stock was unified per-accessory; accessory_id is the source of
        // truth for new imports.
        'accessory_price_id',
        'accessory_id',
        'quantity',
        'price_mode',
        'unit_price',
        'package_size',
        'package_count',
        'total_cost',
        'notes',
        'imported_by_user_id',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'unit_price' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public const MODE_PER_ITEM = 'per_item';
    public const MODE_PER_PACKAGE = 'per_package';

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function accessoryPrice()
    {
        return $this->belongsTo(AccessoryPrice::class);
    }

    public function accessory()
    {
        return $this->belongsTo(Accessory::class);
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
