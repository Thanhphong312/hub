<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'system_id', 'ref_id', 'note', 'status', 'ship_type', 'shipping_label',
        'shipping_label_converted', 'convert_label', 'proof_image',
        'tracking_id', 'print_cost', 'shipping_cost', 'total_cost',
        'paid_cost', 'completed_time', 'production', 'stock_decremented',
        'gangsheet_group_id', 'qr_bg_status', 'qr_bg_checked_at',
        'resend_of_order_id', 'resend_method', 'resend_reason', 'base_support',
        'partner_id', 'partner_revenue', 'partner_locked_at',
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
            'qr_bg_checked_at' => 'datetime',
            'production' => 'boolean',
            'shipping_label_converted' => 'boolean',
            'stock_decremented' => 'boolean',
            'partner_locked_at' => 'datetime',
        ];
    }

    /** Partner-role user this order's revenue is split to (nullable). */
    public function partner()
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    const STATUS_NEW_ORDER = 0;
    const STATUS_PROCESSING = 1;
    const STATUS_WRONGSIZE = 2;
    const STATUS_FIXED = 3;
    const STATUS_REPRINT = 4;
    const STATUS_ONHOLD = 5;
    const STATUS_SHIPPED = 6;
    const STATUS_CANCELLED = 7;
    const STATUS_PENDING_CANCEL = 8;   // seller requested cancel, awaiting admin decision

    const STATUS_MAP = [
        0 => 'new_order',
        1 => 'producing',
        2 => 'wrongsize',
        3 => 'fixed',
        4 => 'reprint',
        5 => 'onhold',
        6 => 'shipped',
        7 => 'cancelled',
        8 => 'pending_cancel',
    ];

    /**
     * Orders that have a shipping label but no tracking yet — the queue the
     * "Fetch Tracking" feature (app button + /gettracking command) works on.
     */
    public function scopePendingTracking($query)
    {
        return $query->whereNull('tracking_id')
            ->whereNotNull('shipping_label')
            ->where('shipping_label', '!=', '');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Resend config (admin-editable via Settings → AppSetting `resend_config`).
     *   methods[]: {key, label, price, ship_type, desc}
     *   reasons[]: {key, label, base_support: 'quota'|'full'|'none'}
     *     - quota: free base for the first N resends/month, then % support
     *     - full:  base always free
     *     - none:  seller pays full base
     *   quota: {default_free, after_free_support_pct}
     * Seller always pays the full shipping price.
     */
    public static function resendConfig(): array
    {
        $defaults = [
            'methods' => [
                ['key' => 'label', 'label' => 'Gửi lại bằng label có tracking', 'price' => 5.99, 'ship_type' => self::SHIP_SELLER, 'desc' => 'Khách nhận trong 1–5 ngày làm việc'],
                ['key' => 'stamp', 'label' => 'Gửi lại bằng stamp (không tracking)', 'price' => 1.30, 'ship_type' => self::SHIP_STAMP, 'desc' => 'Khách nhận trong 1–5 ngày làm việc'],
            ],
            // Each reason sets the workshop support % per fee component (0–100).
            // quota_based: when true, the support only applies within the monthly
            // free quota; past it, support is scaled by quota.after_free_support_pct.
            'reasons' => [
                ['key' => 'third_party', 'label' => 'Lỗi từ bên thứ ba', 'quota_based' => true,
                    'support' => ['base_cost' => 100, '2nd_fee' => 0, 'accessory' => 0]],
                ['key' => 'seller', 'label' => 'Lỗi từ seller', 'quota_based' => false,
                    'support' => ['base_cost' => 0, '2nd_fee' => 0, 'accessory' => 0]],
            ],
            'quota' => ['default_free' => 20, 'after_free_support_pct' => 50],
            // Fee components that can appear on an order (for the settings UI).
            'fee_components' => [
                ['key' => 'base_cost', 'label' => 'Base cost'],
                ['key' => '2nd_fee', 'label' => 'Phí mặt thêm'],
                ['key' => 'accessory', 'label' => 'Accessory'],
            ],
        ];

        $stored = AppSetting::getJson('resend_config', []);
        if (is_array($stored)) {
            if (!empty($stored['methods'])) $defaults['methods'] = $stored['methods'];
            if (!empty($stored['reasons'])) $defaults['reasons'] = $stored['reasons'];
            if (!empty($stored['quota']))   $defaults['quota'] = array_merge($defaults['quota'], $stored['quota']);
        }
        return $defaults;
    }

    // The original order this is a resend of (null for normal orders).
    public function resendOf()
    {
        return $this->belongsTo(Order::class, 'resend_of_order_id');
    }

    // Resend orders created from this order.
    public function resends()
    {
        return $this->hasMany(Order::class, 'resend_of_order_id');
    }

    // Current automation gangsheet group (null = not assigned / back in pending).
    public function gangsheetGroup()
    {
        return $this->belongsTo(GangsheetGroup::class);
    }

    // Delivery tracking state from ShipEngine (filled by the tracking:check cron).
    public function tracking()
    {
        return $this->hasOne(OrderTracking::class);
    }

    public function address()
    {
        return $this->hasOne(Address::class);
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    // All cancel requests raised against this order (history; re-request allowed
    // after a rejection).
    public function cancelRequests()
    {
        return $this->hasMany(CancelRequest::class);
    }

    // The most recent cancel request — drives the pending_cancel badge and the
    // admin accept/reject column. Eager-loaded on the orders list/detail.
    public function latestCancelRequest()
    {
        return $this->hasOne(CancelRequest::class)->latestOfMany();
    }

    // Image-URL validation summary (1:1). Null until the order has been seen
    // by the validation flow. See App\Services\ImageValidationService.
    public function imageValidation()
    {
        return $this->hasOne(OrderImageValidation::class);
    }

    public function imageValidationFailures()
    {
        return $this->hasMany(OrderImageValidationItem::class);
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
