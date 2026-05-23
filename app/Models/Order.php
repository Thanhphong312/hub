<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'system_id', 'ref_id', 'status', 'ship_type', 'shipping_label',
        'shipping_label_converted', 'convert_label',
        'tracking_id', 'print_cost', 'shipping_cost', 'total_cost',
        'paid_cost', 'completed_time', 'production', 'stock_decremented',
    ];

    // convert_label is admin/support-only — hidden by default, controllers call
    // makeVisible('convert_label') when the requesting user is staff.
    protected $hidden = ['convert_label'];

    protected function casts(): array
    {
        return [
            'completed_time' => 'datetime',
            'production' => 'boolean',
            'shipping_label_converted' => 'boolean',
            'stock_decremented' => 'boolean',
        ];
    }

    const STATUS_NEW_ORDER = 0;
    const STATUS_PROCESSING = 1;
    const STATUS_WRONGSIZE = 2;
    const STATUS_FIXED = 3;
    const STATUS_REPRINT = 4;
    const STATUS_ONHOLD = 5;
    const STATUS_SHIPPED = 6;
    const STATUS_CANCELLED = 7;

    const STATUS_MAP = [
        0 => 'new_order',
        1 => 'processing',
        2 => 'wrongsize',
        3 => 'fixed',
        4 => 'reprint',
        5 => 'onhold',
        6 => 'shipped',
        7 => 'cancelled',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function address()
    {
        return $this->hasOne(Address::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function getStatusNameAttribute(): string
    {
        return self::STATUS_MAP[$this->status] ?? 'unknown';
    }

    /**
     * Decrement product_variants.stock and accessory_prices.stock for every
     * item in this order. Idempotent: the `stock_decremented` flag prevents
     * double-deduction when the same order transitions to SHIPPED twice (e.g.
     * re-saving an already-shipped order). Caller is responsible for wrapping
     * this in a DB::transaction if it's part of a larger update.
     */
    public function decrementStockForShipment(): void
    {
        if ($this->stock_decremented) return;

        // Load accessoryPrices to resolve their parent accessory_id; physical
        // stock is now tracked on `accessories.stock` (shared across tiers),
        // so an item with multiple tier-priced accessories of the same group
        // still consumes from one pool.
        $this->loadMissing('items.accessoryPrices');
        foreach ($this->items as $item) {
            $qty = max(1, (int) ($item->quantity ?? 1));
            if ($item->product_variant_id) {
                ProductVariant::where('id', $item->product_variant_id)->decrement('stock', $qty);
            }
            // Dedupe accessory_id within the item so picking 2 tier-prices of
            // the same accessory in one item only deducts once.
            $accessoryIds = $item->accessoryPrices->pluck('accessory_id')->filter()->unique();
            foreach ($accessoryIds as $aid) {
                Accessory::where('id', $aid)->decrement('stock', $qty);
            }
        }

        // Per-order label consumption: 1 shipped order = 1 label paper, no
        // matter how many items the order has. Only fires if a default-label
        // supply is configured.
        Supply::where('is_default_label', true)->decrement('stock', 1);
        $this->stock_decremented = true;
        $this->saveQuietly();
    }
}
