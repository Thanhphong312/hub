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

    /**
     * Template of the designs behind these _qr metas: each "front_qr_2" maps to
     * its item's "front" analysis. One template → its id; none or a mix → null.
     */
    public static function templateIdForQrMetas(array $metaIds): ?int
    {
        return self::templateIdsForQrMetaSets([0 => $metaIds])[0] ?? null;
    }

    /**
     * Same for many sets at once: [setKey => [_qr meta id…]] → [setKey => template id|null].
     * Two queries total, however many sets.
     */
    public static function templateIdsForQrMetaSets(array $sets): array
    {
        $all = array_values(array_unique(array_map('intval', array_merge([], ...array_values($sets)))));
        if (! $all) return array_map(fn () => null, $sets);

        $sourceOf = [];   // qr meta id → "item:key"
        foreach (OrderItemMeta::whereIn('id', $all)->get(['id', 'order_item_id', 'key']) as $m) {
            if (preg_match('/^(.+)_qr(_\d+)?$/', $m->key, $mm)) $sourceOf[$m->id] = $m->order_item_id.':'.$mm[1];
        }
        $items = array_unique(array_map(fn ($s) => (int) explode(':', $s)[0], $sourceOf));
        $templateOf = [];   // "item:key" → template id
        foreach (self::whereIn('order_item_id', $items)->whereNotNull('template_id')
            ->get(['order_item_id', 'meta_key', 'template_id']) as $a) {
            $templateOf[$a->order_item_id.':'.$a->meta_key] = $a->template_id;
        }

        $out = [];
        foreach ($sets as $k => $ids) {
            $tids = [];
            foreach ($ids as $id) {
                $src = $sourceOf[(int) $id] ?? null;
                $tids[] = $src ? ($templateOf[$src] ?? null) : null;
            }
            $tids = array_unique($tids);
            $out[$k] = (count($tids) === 1 && $tids[0]) ? (int) $tids[0] : null;
        }

        return $out;
    }

    /**
     * Re-derive sticker_template_id of every one-page native gang (Sticker
     * Sheet gangs) — after templates are created, assigned or changed.
     * Returns how many gangs changed.
     */
    public static function syncGangTemplates(): int
    {
        $gangs = Gangsheet::where('page_format', 'native')
            ->get(['id', 'meta_ids', 'sticker_template_id']);
        $sets = [];
        foreach ($gangs as $g) $sets[$g->id] = $g->meta_ids ?? [];
        $want = self::templateIdsForQrMetaSets($sets);

        $n = 0;
        foreach ($gangs as $g) {
            if (($want[$g->id] ?? null) !== $g->sticker_template_id) {
                Gangsheet::whereKey($g->id)->update(['sticker_template_id' => $want[$g->id] ?? null]);
                $n++;
            }
        }

        return $n;
    }
}
