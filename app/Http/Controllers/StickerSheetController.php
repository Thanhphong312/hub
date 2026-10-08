<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderItemMeta;
use App\Models\StickerSheetAnalysis;
use App\Models\StickerTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sticker Sheet analysis (bullstart-app → "Sticker Sheet" menu).
 *
 * The desktop app downloads each Sticker Sheet design, counts the stickers on
 * it and fingerprints it (256-bit dHash), then posts the result here. The hub
 * stores one row per source design meta and matches it to a saved template
 * ("mẫu") by fingerprint, so the same sheet across orders lands on the same
 * template — now and for every order that comes in later.
 */
class StickerSheetController extends Controller
{
    /** Source design keys analysed (same set the converter reads). */
    private const SOURCE_KEYS = ['front', 'back', 'left', 'right', 'neck', 'special'];

    /**
     * Max Hamming distance (of 256 bits) for "same design". Re-uploads of one
     * design score ≲ 10; different sheets were measured at 106–130.
     */
    public const MATCH_MAX = 24;

    /** order_type labels whose convert layout is Sticker Sheet. */
    private static function stickerOrderTypes(): array
    {
        $map = AppSetting::getJson('convert_layouts', []);

        return array_keys(array_filter((array) $map, fn ($v) => $v === 'sticker_sheet'));
    }

    /** Source metas of non-cancelled Sticker Sheet items. */
    private static function stickerMetaQuery()
    {
        $types = self::stickerOrderTypes();

        return OrderItemMeta::query()
            ->whereIn('key', self::SOURCE_KEYS)
            ->where('value', 'regexp', '^[[:space:]]*https?://')
            ->whereHas('orderItem', function ($q) use ($types) {
                $q->whereHas('productVariant.product', fn ($p) => $p->whereIn('order_type', $types ?: ['__none__']))
                    ->whereHas('order', fn ($o) => $o->where('status', '!=', Order::STATUS_CANCELLED));
            });
    }

    /** Metas whose analysis is missing or stale (link changed). */
    private static function unanalysedQuery()
    {
        return self::stickerMetaQuery()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('sticker_sheet_analyses as a')
                    ->whereColumn('a.order_item_meta_id', 'order_item_metas.id')
                    ->whereColumn('a.source_url', 'order_item_metas.value');
            });
    }

    /**
     * GET /sticker-sheets/pending?limit=
     * Designs still to analyse, ONE per file: a link already analysed on
     * another order is not analysed again — its result is copied over first
     * (reuseSameLinks) — and several pending metas on the same file come back
     * as a single item.
     */
    public function pending(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 50), 1), 500);
        self::reuseSameLinks();

        $byKey = [];
        foreach (self::unanalysedQuery()->with('orderItem:id,order_id', 'orderItem.order:id,system_id')
            ->orderByDesc('id')->get(['id', 'order_item_id', 'key', 'value']) as $m) {
            $k = self::designKey($m->value);
            $byKey[$k] ??= [
                'order_item_meta_id' => $m->id,
                'order_item_id' => $m->order_item_id,
                'order_id' => $m->orderItem?->order_id,
                'system_id' => $m->orderItem?->order?->system_id,
                'meta_key' => $m->key,
                'url' => trim($m->value),
            ];
        }

        return response()->json([
            'total' => count($byKey),
            'items' => array_slice(array_values($byKey), 0, $limit),
        ]);
    }

    /**
     * Copy an existing analysis onto every unanalysed meta that points at the
     * same file (same design_key). Returns how many were filled.
     */
    private static function reuseSameLinks(): int
    {
        $metas = self::unanalysedQuery()->with('orderItem:id,order_id')
            ->get(['id', 'order_item_id', 'key', 'value']);
        if ($metas->isEmpty()) return 0;

        $keyOf = $metas->mapWithKeys(fn ($m) => [$m->id => self::designKey($m->value)]);
        $known = StickerSheetAnalysis::whereIn('design_key', $keyOf->unique()->values())
            ->orderByDesc('updated_at')->get()->unique('design_key')->keyBy('design_key');
        if ($known->isEmpty()) return 0;

        $n = 0;
        foreach ($metas as $m) {
            $src = $known->get($keyOf[$m->id]);
            if (! $src || ! $m->orderItem) continue;
            StickerSheetAnalysis::updateOrCreate(
                ['order_item_meta_id' => $m->id],
                [
                    'order_item_id' => $m->order_item_id,
                    'order_id' => $m->orderItem->order_id,
                    'meta_key' => $m->key,
                    'source_url' => $m->value,
                    'design_key' => $src->design_key,
                ] + $src->only([
                    'sticker_count', 'auto_count', 'count_edited', 'phash', 'boxes', 'mode',
                    'width', 'height', 'template_id', 'match_distance', 'template_manual',
                    'analyzed_by_user_id',
                ])
            );
            $n++;
        }

        return $n;
    }

    /** Drive file id for Drive links (any URL shape), else the trimmed URL. */
    private static function designKey(string $url): string
    {
        $url = trim($url);
        if (preg_match('#(?:/file/d/|[?&]id=)([a-zA-Z0-9_-]+)#', $url, $m) && str_contains($url, 'google.com')) {
            return 'gd:'.$m[1];
        }

        return substr($url, 0, 191);
    }

    /**
     * POST /sticker-sheets/analyses
     *   results: [{ order_item_meta_id, source_url, sticker_count, phash,
     *               boxes, mode, width, height }]
     * Upserts each result and matches it to a template.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'results' => 'required|array|min:1|max:200',
            'results.*.order_item_meta_id' => 'required|integer',
            'results.*.source_url' => 'required|string',
            'results.*.sticker_count' => 'required|integer|min:0|max:65535',
            'results.*.phash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'results.*.boxes' => 'nullable|array',
            'results.*.mode' => 'nullable|string|max:16',
            'results.*.width' => 'nullable|integer',
            'results.*.height' => 'nullable|integer',
        ]);

        $metas = OrderItemMeta::with('orderItem:id,order_id')
            ->whereIn('id', collect($data['results'])->pluck('order_item_meta_id'))
            ->get()->keyBy('id');
        $templates = StickerTemplate::get(['id', 'phash', 'sticker_count']);

        $saved = 0;
        $matched = 0;
        foreach ($data['results'] as $r) {
            $meta = $metas->get($r['order_item_meta_id']);
            if (! $meta || ! $meta->orderItem) continue;

            [$templateId, $distance] = self::bestTemplate($r['phash'], $templates);
            $existing = StickerSheetAnalysis::where('order_item_meta_id', $meta->id)->first();
            // A template chosen by hand survives a re-analysis of the same link.
            $keepManual = $existing && $existing->template_manual && $existing->source_url === $r['source_url'];

            StickerSheetAnalysis::updateOrCreate(
                ['order_item_meta_id' => $meta->id],
                [
                    'order_item_id' => $meta->order_item_id,
                    'order_id' => $meta->orderItem->order_id,
                    'meta_key' => $meta->key,
                    'source_url' => $r['source_url'],
                    'design_key' => self::designKey($r['source_url']),
                    'sticker_count' => $r['sticker_count'],
                    'auto_count' => $r['sticker_count'],
                    'count_edited' => false,
                    'phash' => $r['phash'],
                    'boxes' => $r['boxes'] ?? null,
                    'mode' => $r['mode'] ?? null,
                    'width' => $r['width'] ?? null,
                    'height' => $r['height'] ?? null,
                    'template_id' => $keepManual ? $existing->template_id : $templateId,
                    'match_distance' => $keepManual ? $existing->match_distance : $distance,
                    'template_manual' => $keepManual,
                    'analyzed_by_user_id' => $request->user()?->id,
                ]
            );
            $saved++;
            if ($keepManual ? $existing->template_id : $templateId) $matched++;
        }

        // The same files on other orders take this result without analysing.
        $copied = self::reuseSameLinks();

        return response()->json([
            'message' => "Đã lưu {$saved} phân tích, {$matched} khớp mẫu".($copied ? ", chép sang {$copied} sheet cùng link" : ''),
            'saved' => $saved,
            'matched' => $matched,
            'copied' => $copied,
        ]);
    }

    /**
     * GET /sticker-sheets/analyses
     *   template_id | unmatched=1 | search (system_id list) | date_from/date_to
     *   (order created, UTC) | per_page
     */
    public function index(Request $request)
    {
        $q = StickerSheetAnalysis::with([
            'template:id,name,sticker_count',
            'order:id,system_id,created_at,status,user_id',
            'order.user:id,name',
            'orderItem:id,quantity',
        ])->orderByDesc('order_id')->orderBy('id');

        if ($request->filled('template_id')) $q->where('template_id', (int) $request->template_id);
        if ($request->boolean('unmatched')) $q->whereNull('template_id');
        if ($request->filled('search')) {
            $terms = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $request->search))));
            if ($terms) {
                $q->whereHas('order', function ($o) use ($terms) {
                    $o->where(function ($w) use ($terms) {
                        foreach ($terms as $t) $w->orWhere('system_id', 'like', "%{$t}%");
                    });
                });
            }
        }
        if ($request->filled('date_from')) $q->whereHas('order', fn ($o) => $o->whereDate('created_at', '>=', $request->date_from));
        if ($request->filled('date_to')) $q->whereHas('order', fn ($o) => $o->whereDate('created_at', '<=', $request->date_to));

        $page = $q->paginate(min((int) $request->input('per_page', 50), 200));

        return response()->json($page);
    }

    /** GET /sticker-sheets/analyses/{analysis} */
    public function show(StickerSheetAnalysis $analysis)
    {
        return response()->json($analysis->load([
            'template:id,name,sticker_count',
            'order:id,system_id,created_at,status,user_id',
            'order.user:id,name',
            'orderItem:id,quantity',
        ]));
    }

    /**
     * PUT /sticker-sheets/analyses/{analysis}
     *   sticker_count (manual fix) and/or template_id (null = unassign)
     */
    public function update(Request $request, StickerSheetAnalysis $analysis)
    {
        $data = $request->validate([
            'sticker_count' => 'sometimes|integer|min:0|max:65535',
            'template_id' => 'sometimes|nullable|integer|exists:sticker_templates,id',
        ]);

        // Every sheet on the same file is the same design: the fix applies to all.
        $changes = [];
        if (array_key_exists('sticker_count', $data)) {
            $changes['sticker_count'] = $data['sticker_count'];
            $changes['count_edited'] = $data['sticker_count'] !== $analysis->auto_count;
        }
        if (array_key_exists('template_id', $data)) {
            $changes['template_id'] = $data['template_id'];
            $changes['template_manual'] = true;
            $changes['match_distance'] = $data['template_id']
                ? self::hamming($analysis->phash, StickerTemplate::find($data['template_id'])->phash)
                : null;
        }
        $same = StickerSheetAnalysis::where('design_key', $analysis->design_key)->update($changes);

        return response()->json([
            'analysis' => $analysis->fresh()->load('template:id,name,sticker_count'),
            'same_file' => $same,
        ]);
    }

    /**
     * GET /sticker-sheets/groups
     * Designs not on any template, clustered by fingerprint — sheets that
     * repeat across orders but have no template yet. Only groups of 2+.
     */
    public function groups()
    {
        $rows = StickerSheetAnalysis::whereNull('template_id')
            ->with('order:id,system_id')
            ->orderBy('id')
            ->get(['id', 'order_id', 'phash', 'sticker_count', 'source_url', 'mode']);

        $bins = array_map(fn ($r) => hex2bin($r->phash), $rows->all());
        $groupOf = [];
        $groups = [];
        foreach ($rows as $i => $r) {
            if (isset($groupOf[$i])) continue;
            $members = [$i];
            $groupOf[$i] = true;
            for ($j = $i + 1; $j < count($rows); $j++) {
                if (isset($groupOf[$j])) continue;
                if (self::hammingBin($bins[$i], $bins[$j]) <= self::MATCH_MAX) {
                    $members[] = $j;
                    $groupOf[$j] = true;
                }
            }
            if (count($members) < 2) continue;
            $ms = collect($members)->map(fn ($k) => $rows[$k]);
            $counts = $ms->pluck('sticker_count')->countBy()->sortDesc();
            $groups[] = [
                'analysis_ids' => $ms->pluck('id')->values(),
                'orders' => $ms->map(fn ($a) => $a->order?->system_id)->filter()->unique()->values(),
                'size' => $ms->count(),
                'sticker_count' => (int) $counts->keys()->first(),   // most common
                'counts' => $counts,
                'sample' => ['id' => $ms->first()->id, 'url' => $ms->first()->source_url],
            ];
        }
        usort($groups, fn ($a, $b) => $b['size'] <=> $a['size']);

        return response()->json(['groups' => $groups]);
    }

    /**
     * GET /sticker-sheets/summary
     * Open orders (not shipped / cancelled) grouped by the sheet they print:
     * one group per template, then designs on no template clustered by
     * fingerprint. Each group lists its system IDs so the batch can be pulled.
     */
    public function summary()
    {
        self::reuseSameLinks();
        $closed = [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED];

        $rows = StickerSheetAnalysis::with([
            'template:id,name,sticker_count,sample_url,note',
            'order:id,system_id,status,created_at',
            'orderItem:id,quantity',
        ])->whereHas('order', fn ($o) => $o->whereNotIn('status', $closed))
            ->orderBy('order_id')->get();

        $pack = function ($members, $template) {
            $members = collect($members);
            $first = $members->first();
            $orders = $members->groupBy('order_id')->map(fn ($ms) => [
                'order_id' => $ms->first()->order_id,
                'system_id' => $ms->first()->order?->system_id,
                'status' => $ms->first()->order?->status,
                'sheets' => $ms->sum(fn ($a) => max(1, (int) ($a->orderItem?->quantity ?? 1))),
            ])->values();

            return [
                'template' => $template ? $template->only(['id', 'name', 'sticker_count', 'note']) : null,
                'sample_url' => $template?->sample_url ?: $first->source_url,
                'sample_analysis_id' => $first->id,
                'sticker_count' => $template?->sticker_count ?? (int) $members->pluck('sticker_count')->countBy()->sortDesc()->keys()->first(),
                'orders' => $orders,
                'orders_count' => $orders->count(),
                'sheets' => $orders->sum('sheets'),
                'analysis_ids' => $members->pluck('id')->values(),
            ];
        };

        $groups = [];
        foreach ($rows->whereNotNull('template_id')->groupBy('template_id') as $ms) {
            $groups[] = $pack($ms, $ms->first()->template);
        }
        usort($groups, fn ($a, $b) => $b['sheets'] <=> $a['sheets']);

        // No template yet: cluster by fingerprint so repeats still show together.
        $loose = $rows->whereNull('template_id')->values();
        $bins = $loose->map(fn ($a) => hex2bin($a->phash))->all();
        $taken = [];
        $unmatched = [];
        foreach ($loose as $i => $a) {
            if (isset($taken[$i])) continue;
            $members = [$a];
            $taken[$i] = true;
            for ($j = $i + 1; $j < count($loose); $j++) {
                if (! isset($taken[$j]) && self::hammingBin($bins[$i], $bins[$j]) <= self::MATCH_MAX) {
                    $members[] = $loose[$j];
                    $taken[$j] = true;
                }
            }
            $unmatched[] = $pack($members, null);
        }
        usort($unmatched, fn ($a, $b) => $b['sheets'] <=> $a['sheets']);

        // Open Sticker Sheet designs nobody has analysed yet.
        $notAnalysed = self::unanalysedQuery()
            ->whereHas('orderItem.order', fn ($o) => $o->whereNotIn('status', $closed))
            ->with('orderItem.order:id,system_id')->get(['id', 'order_item_id'])
            ->map(fn ($m) => $m->orderItem?->order?->system_id)->filter()->unique()->values();

        return response()->json([
            'groups' => $groups,
            'unmatched' => $unmatched,
            'not_analysed' => $notAnalysed,
            'totals' => [
                'orders' => $rows->pluck('order_id')->unique()->count(),
                'sheets' => collect($groups)->sum('sheets') + collect($unmatched)->sum('sheets'),
            ],
        ]);
    }

    // ---------------------------------------------------------------- templates

    /** GET /sticker-templates */
    public function templates()
    {
        return response()->json(
            StickerTemplate::withCount('analyses')
                ->withCount(['analyses as orders_count' => fn ($q) => $q->select(DB::raw('count(distinct order_id)'))])
                ->with('creator:id,name')
                ->orderBy('name')->get()
        );
    }

    /**
     * POST /sticker-templates
     *   name, note?, from_analysis_id (the design it is saved from),
     *   sticker_count? (defaults to that analysis), analysis_ids? (also
     *   assign these — a whole "giống nhau" group)
     * Then every unmatched analysis within MATCH_MAX joins it.
     */
    public function storeTemplate(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'note' => 'nullable|string|max:2000',
            'from_analysis_id' => 'required|integer|exists:sticker_sheet_analyses,id',
            'sticker_count' => 'nullable|integer|min:0|max:65535',
            'analysis_ids' => 'nullable|array',
            'analysis_ids.*' => 'integer',
        ]);

        $from = StickerSheetAnalysis::findOrFail($data['from_analysis_id']);

        return DB::transaction(function () use ($data, $from, $request) {
            $template = StickerTemplate::create([
                'name' => $data['name'],
                'note' => $data['note'] ?? null,
                'sticker_count' => $data['sticker_count'] ?? $from->sticker_count,
                'phash' => $from->phash,
                'sample_url' => $from->source_url,
                'created_by_user_id' => $request->user()?->id,
            ]);

            $ids = array_unique(array_merge([$from->id], $data['analysis_ids'] ?? []));
            foreach (StickerSheetAnalysis::whereIn('id', $ids)->get() as $a) {
                $a->update([
                    'template_id' => $template->id,
                    'match_distance' => self::hamming($a->phash, $template->phash),
                    'template_manual' => true,
                ]);
            }
            $auto = self::rematchUnmatched();

            return response()->json([
                'message' => "Đã lưu mẫu \"{$template->name}\"",
                'template' => $template,
                'assigned' => count($ids) + $auto,
            ], 201);
        });
    }

    /** PUT /sticker-templates/{template} — name, note, sticker_count */
    public function updateTemplate(Request $request, StickerTemplate $template)
    {
        $template->update($request->validate([
            'name' => 'sometimes|string|max:120',
            'note' => 'sometimes|nullable|string|max:2000',
            'sticker_count' => 'sometimes|integer|min:0|max:65535',
        ]));

        return response()->json(['template' => $template]);
    }

    /** DELETE /sticker-templates/{template} — its sheets go back to unmatched. */
    public function destroyTemplate(StickerTemplate $template)
    {
        StickerSheetAnalysis::where('template_id', $template->id)
            ->update(['template_id' => null, 'match_distance' => null, 'template_manual' => false]);
        $template->delete();

        return response()->json(['message' => 'Đã xoá mẫu']);
    }

    /** POST /sticker-templates/rematch — retry every unmatched analysis. */
    public function rematch()
    {
        $n = self::rematchUnmatched();

        return response()->json(['message' => "Khớp thêm {$n} sheet vào mẫu", 'matched' => $n]);
    }

    // ------------------------------------------------------------------ helpers

    /** Give each unmatched analysis its nearest template within MATCH_MAX. */
    private static function rematchUnmatched(): int
    {
        $templates = StickerTemplate::get(['id', 'phash', 'sticker_count']);
        if ($templates->isEmpty()) return 0;
        $n = 0;
        StickerSheetAnalysis::whereNull('template_id')->select('id', 'phash')
            ->chunkById(500, function ($rows) use ($templates, &$n) {
                foreach ($rows as $a) {
                    [$tid, $dist] = self::bestTemplate($a->phash, $templates);
                    if ($tid) {
                        StickerSheetAnalysis::whereKey($a->id)->update(['template_id' => $tid, 'match_distance' => $dist]);
                        $n++;
                    }
                }
            });

        return $n;
    }

    /** [template_id, distance] of the nearest template within MATCH_MAX, else [null, null]. */
    private static function bestTemplate(string $phash, $templates): array
    {
        $bin = hex2bin($phash);
        $best = null;
        $bestD = PHP_INT_MAX;
        foreach ($templates as $t) {
            $d = self::hammingBin($bin, hex2bin($t->phash));
            if ($d < $bestD) { $bestD = $d; $best = $t->id; }
        }

        return $bestD <= self::MATCH_MAX ? [$best, $bestD] : [null, null];
    }

    private static function hamming(string $a, string $b): int
    {
        return self::hammingBin(hex2bin($a), hex2bin($b));
    }

    /** Bit difference of two equal-length binary strings. */
    private static function hammingBin(string $a, string $b): int
    {
        static $pop = null;
        if ($pop === null) {
            for ($i = 0; $i < 256; $i++) $pop[$i] = substr_count(decbin($i), '1');
        }
        $d = 0;
        foreach (count_chars($a ^ $b, 1) as $byte => $times) $d += $pop[$byte] * $times;

        return $d;
    }
}
