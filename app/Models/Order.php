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
        'gangsheet_group_id',
    ];

    // ship_type values.
    const SHIP_TIKTOK = 'tiktok_ship';   // carrier label provided by seller
    const SHIP_SELLER = 'seller_ship';   // address only, we generate a label
    const SHIP_STAMP  = 'stamp';         // address only, ship by postage stamp (no tracking)

    // Stamp shipping economics (note "Đơn stamp"). Defaults below; admin can
    // override via app_settings.stamp_config (Settings → Stamp).
    //   base fee covers up to `base_items`; each item beyond that adds `fee`;
    //   plus a flat `handling_fee`. `max_items` caps a stamp order.
    const STAMP_FEE          = 1.00;   // per item (base + each extra)
    const STAMP_HANDLING_FEE = 0.00;   // flat per order (no handling by default)
    const STAMP_BASE_ITEMS   = 2;      // items covered by the base fee
    const STAMP_MAX_ITEMS    = 4;      // limit before forcing a normal label

    public static function stampConfig(): array
    {
        $c = AppSetting::getJson('stamp_config', []);
        return [
            'fee'          => (float) ($c['fee']          ?? self::STAMP_FEE),
            'handling_fee' => (float) ($c['handling_fee'] ?? self::STAMP_HANDLING_FEE),
            'base_items'   => (int)   ($c['base_items']   ?? self::STAMP_BASE_ITEMS),
            'max_items'    => (int)   ($c['max_items']    ?? self::STAMP_MAX_ITEMS),
        ];
    }

    /**
     * Handling fee for a stamp order: base fee (covers base_items) + $fee for
     * each item beyond base_items + flat handling_fee. Caller enforces max_items.
     *   e.g. fee=1, base=2, handling=0.30 → 2 items $1.30, 3 items $2.30, 4 $3.30
     */
    public static function stampShippingCost(int $totalQty): float
    {
        $c = self::stampConfig();
        $qty = max(1, $totalQty);
        $extra = max(0, $qty - $c['base_items']);
        return round($c['fee'] + $extra * $c['fee'] + $c['handling_fee'], 2);
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
        1 => 'producing',
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

    // Current automation gangsheet group (null = not assigned / back in pending).
    public function gangsheetGroup()
    {
        return $this->belongsTo(GangsheetGroup::class);
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

    /**
     * Reverse of decrementStockForShipment(): when an order leaves the
     * shipped state, return the 1 label-paper unit that the ship event
     * consumed. Idempotent via the same `stock_decremented` flag, so it
     * only ever restocks what was actually deducted (no-op otherwise).
     */
    public function restockForUnship(): void
    {
        if (!$this->stock_decremented) return;

        Supply::where('is_default_label', true)->increment('stock', 1);
        $this->stock_decremented = false;
        $this->saveQuietly();
    }
}
