<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessoryPrice extends Model
{
    protected $fillable = ['accessory_id', 'tier_id', 'style', 'accessory_code', 'price', 'stock', 'purchase_url'];

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
