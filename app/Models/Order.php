<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'system_id', 'ref_id', 'status', 'ship_type', 'shipping_label',
        'shipping_label_converted', 'convert_label', 'proof_image',
        'tracking_id', 'print_cost', 'shipping_cost', 'total_cost',
        'paid_cost', 'completed_time', 'production', 'stock_decremented',
    ];

    // ship_type values.
    const SHIP_TIKTOK = 'tiktok_ship';   // carrier label provided by seller
    const SHIP_SELLER = 'seller_ship';   // address only, we generate a label
    const SHIP_STAMP  = 'stamp';         // address only, ship by postage stamp (no tracking)

    // Stamp shipping economics (note "Đơn stamp", 2026-05-28):
    //   $1 per stamp, 1 stamp covers up to 2 items, + $0.30 handling fee.
    //   Hard cap 4 items per stamp order — above that the seller must use a
    //   normal label.
    const STAMP_FEE            = 1.00;   // per stamp
    const STAMP_HANDLING_FEE   = 0.30;   // flat per order
    const STAMP_ITEMS_PER_UNIT = 2;      // items covered by one stamp
    const STAMP_MAX_ITEMS      = 4;      // limit before forcing a normal label

    /**
     * Shipping (handling) fee for a stamp order: ceil(items / 2) stamps × $1
     * plus the flat $0.30 handling fee. Caller must enforce STAMP_MAX_ITEMS.
     */
    public static function stampShippingCost(int $totalQty): float
    {
        $stamps = (int) ceil(max(1, $totalQty) / self::STAMP_ITEMS_PER_UNIT);
        return round($stamps * self::STAMP_FEE + self::STAMP_HANDLING_FEE, 2);
    }

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
     * Stock bookkeeping on ship:
     *  - Label paper (default-label supply) still decrements 1 per shipped
     *    order — that physical consumption is tied 1:1 to a ship event.
     *  - Variant / accessory_price stock is NOT touched here anymore. Per
     *    operator decision (2026-05-27), variant + accessory inventory is
     *    import-only for now; the deduction model will be reworked later
     *    so each ship event records the chosen accessory_price separately.
     *
     * Idempotent via the `stock_decremented` flag so re-saving a shipped
     * order doesn't double-tick the label-paper count.
     */
    public function decrementStockForShipment(): void
    {
        if ($this->stock_decremented) return;

        Supply::where('is_default_label', true)->decrement('stock', 1);
        $this->stock_decremented = true;
        $this->saveQuietly();
    }
}
