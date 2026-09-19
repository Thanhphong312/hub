<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessoryPrice extends Model
{
    protected $fillable = ['accessory_id', 'tier_id', 'style', 'accessory_code', 'price', 'partner_price', 'stock', 'purchase_url', 'image_des'];

    // Standalone price for holo when it's the ONLY accessory on an
    // addon-only item (no product) — differs from the normal per-tier
    // accessory_prices.price, which prices holo bundled with a product.
    // Admin can override via app_settings.addon_only_holo_price.
    const ADDON_ONLY_HOLO_PRICE = 0.59;

    public static function addonOnlyHoloPrice(): float
    {
        $c = AppSetting::getJson('addon_only_holo_price', []);

        return round((float) ($c['fee'] ?? self::ADDON_ONLY_HOLO_PRICE), 2);
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    /**
     * Which chip add-on this price row names: 'bigchip' | 'smallchip' | 'holo',
     * or '' when it names none.
     *
     * Same patterns AND same priority (holo beats any chip) as chipKindOf() in
     * the desktop app, which drives both the card-skin QR colour and the gang
     * split — the three must never disagree. Matches on `style` first (the data
     * has "Big Chip" / "Small Ship" — note the existing typo), then the short
     * `accessory_code` (BC / SMC / HLG).
     */
    public static function chipKind(?string $text): string
    {
        $s = strtolower(trim((string) $text));
        if ($s === '') return '';
        if (preg_match('/holo/', $s) || preg_match('/\bhlg[a-z]*\b/', $s)) return 'holo';
        if (preg_match('/small/', $s) || preg_match('/\bsmc\b/', $s)) return 'smallchip';
        if (preg_match('/big/', $s) || preg_match('/\bbc\b/', $s)) return 'bigchip';
        return '';
    }

    /** Chip kind of this row: style first, then the short code. */
    public function chipKindOfSelf(): string
    {
        return self::chipKind($this->style) ?: self::chipKind($this->accessory_code);
    }

    public function accessory()
    {
        return $this->belongsTo(Accessory::class);
    }

    public function tier()
    {
        return $this->belongsTo(Tier::class);
    }
}
