<?php

namespace App\Http\Controllers;

use App\Models\AccessoryPrice;
use App\Models\AppSetting;
use App\Models\Material;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockPurchaseOrder;
use App\Models\Supply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Inventory forecasting + AI prediction + purchase orders.
 *
 * Forecast basis (decided with the user): SHIPPED orders in the last N days
 * (default 7). 1 shipped order = 1 label; accessory/material usage = Σ
 * order_item.quantity that used it. Forecast is driven by an ESTIMATED orders
 * per day (defaults to the recent average shipped/lookback, overridable):
 * per-order usage = usage / shipped_orders, and
 * monthly need = per-order usage × orders/day × forecast_days.
 * Suggested buy = need − stock − (đã đặt chờ về).
 */
class InventoryForecastController extends Controller
{
    private function cfg(): array
    {
        $c = AppSetting::getJson('inventory_ai', []);
        return [
            'provider'      => $c['provider']      ?? 'anthropic',         // anthropic | openai
            'model'         => $c['model']         ?? 'claude-sonnet-4-6',
            'api_key'       => $c['api_key']       ?? '',
            // null/0 = auto (recent average shipped/day); a positive value overrides it.
            'orders_per_day'=> isset($c['orders_per_day']) && $c['orders_per_day'] !== '' && $c['orders_per_day'] !== null
                ? (float) $c['orders_per_day'] : null,
            'lookback_days' => (int)   ($c['lookback_days'] ?? 7),
            'forecast_days' => (int)   ($c['forecast_days'] ?? 30),
        ];
    }

    // GET — config without exposing the raw key.
    public function getAiConfig()
    {
        $c = $this->cfg();
        unset($c['api_key']);
        $c['has_api_key'] = $this->cfg()['api_key'] !== '';
        return response()->json($c);
    }

    // PUT — save config. api_key left blank keeps the existing one.
    public function updateAiConfig(Request $request)
    {
        $data = $request->validate([
            'provider'      => 'sometimes|in:anthropic,openai',
            'model'         => 'sometimes|string|max:80',
            'api_key'       => 'sometimes|nullable|string|max:200',
            'orders_per_day'=> 'sometimes|nullable|numeric|min:0|max:100000',
            'lookback_days' => 'sometimes|integer|min:1|max:90',
            'forecast_days' => 'sometimes|integer|min:1|max:365',
        ]);
        $cur = AppSetting::getJson('inventory_ai', []);
        // Don't wipe the key when the field is sent empty.
        if (array_key_exists('api_key', $data) && ($data['api_key'] === null || $data['api_key'] === '')) {
            unset($data['api_key']);
        }
        AppSetting::setJson('inventory_ai', array_merge($cur, $data));
        return $this->getAiConfig();
    }

    /** The computed forecast rows + meta (no AI). */
    public function forecast(Request $request)
    {
        return response()->json($this->computeForecast());
    }

    /**
     * Daily usage series for accessories + materials over the last N days
     * (VN calendar days). Used by the forecast page charts. Usage = Σ shipped
     * order_item.quantity bucketed by the VN date of completed_time.
     */
    public function usageDaily(Request $request)
    {
        $tz = 'Asia/Ho_Chi_Minh';
        $days = max(1, min(120, (int) ($request->get('days') ?: 30)));
        $today = now()->setTimezone($tz)->startOfDay();
        $start = $today->copy()->subDays($days - 1);
        $sinceUtc = $start->copy()->utc();
        $untilUtc = $today->copy()->endOfDay()->utc();

        $dayList = [];
        for ($i = 0; $i < $days; $i++) $dayList[] = $start->copy()->addDays($i)->toDateString();
        $idx = array_flip($dayList);                 // 'YYYY-MM-DD' → position
        $blank = array_fill(0, $days, 0);
        // completed_time is UTC; bucket by the VN calendar date.
        $vnDate = "DATE(CONVERT_TZ(o.completed_time, '+00:00', '+07:00'))";

        // Accessories — per (accessory_id, code).
        $accRows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('order_item_accessories as oia', 'oia.order_item_id', '=', 'oi.id')
            ->join('accessory_prices as ap', 'ap.id', '=', 'oia.accessory_price_id')
            ->join('accessories as a', 'a.id', '=', 'ap.accessory_id')
            ->where('o.status', Order::STATUS_SHIPPED)
            ->whereBetween('o.completed_time', [$sinceUtc, $untilUtc])
            ->groupBy('ap.accessory_id', 'ap.accessory_code', 'd')
            ->selectRaw("ap.accessory_id, ap.accessory_code, a.name as aname, {$vnDate} as d, SUM(oi.quantity) as used")
            ->get();
        $acc = [];
        foreach ($accRows as $r) {
            $key = $r->accessory_id . '|' . ($r->accessory_code ?? '');
            if (!isset($acc[$key])) {
                $acc[$key] = ['label' => $r->aname . ($r->accessory_code ? " [{$r->accessory_code}]" : ''), 'series' => $blank, 'total' => 0];
            }
            if (isset($idx[$r->d])) { $acc[$key]['series'][$idx[$r->d]] = (int) $r->used; $acc[$key]['total'] += (int) $r->used; }
        }

        // Materials — per material_id.
        $matRows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('materials as m', 'm.id', '=', 'oi.material_id')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->where('o.status', Order::STATUS_SHIPPED)
            ->whereBetween('o.completed_time', [$sinceUtc, $untilUtc])
            ->groupBy('oi.material_id', 'd')
            ->selectRaw("oi.material_id, m.name as mname, p.name as pname, {$vnDate} as d, SUM(oi.quantity) as used")
            ->get();
        $mat = [];
        foreach ($matRows as $r) {
            if (!isset($mat[$r->material_id])) {
                $mat[$r->material_id] = ['label' => ($r->pname ? $r->pname . ' · ' : '') . $r->mname, 'series' => $blank, 'total' => 0];
            }
            if (isset($idx[$r->d])) { $mat[$r->material_id]['series'][$idx[$r->d]] = (int) $r->used; $mat[$r->material_id]['total'] += (int) $r->used; }
        }

        $byTotalDesc = fn ($a, $b) => $b['total'] - $a['total'];
        $accOut = array_values($acc); usort($accOut, $byTotalDesc);
        $matOut = array_values($mat); usort($matOut, $byTotalDesc);

        return response()->json([
            'days'        => $dayList,
            'accessories' => $accOut,
            'materials'   => $matOut,
        ]);
    }

    private function computeForecast(): array
    {
        $cfg = $this->cfg();
        $lookback = max(1, $cfg['lookback_days']);

        // Window = exactly `lookback` whole days in Vietnam time, from the
        // start of (today − lookback+1) to the end of today. completed_time is
        // stored in UTC, so convert the VN day-boundaries to UTC for the query.
        $tz = 'Asia/Ho_Chi_Minh';
        $nowVn = now()->setTimezone($tz);
        $sinceVn = $nowVn->copy()->startOfDay()->subDays($lookback - 1);
        $untilVn = $nowVn->copy()->endOfDay();
        $since = $sinceVn->copy()->utc();
        $until = $untilVn->copy()->utc();

        $shippedCount = (int) Order::where('status', Order::STATUS_SHIPPED)
            ->whereBetween('completed_time', [$since, $until])->count();

        // Estimated orders/day: explicit override, else the recent average.
        $avgPerDay = $shippedCount / $lookback;
        $auto = $cfg['orders_per_day'] === null || $cfg['orders_per_day'] <= 0;
        $ordersPerDay = $auto ? $avgPerDay : $cfg['orders_per_day'];

        // Project total orders over the forecast window, then scale each line by
        // its per-order usage (used / shipped_orders). factor = projected/shipped.
        $projOrders = $ordersPerDay * $cfg['forecast_days'];
        $factor = $shippedCount > 0 ? ($projOrders / $shippedCount) : 0;

        // Open (not-yet-received) purchase-order qty per target.
        $poOpen = StockPurchaseOrder::whereIn('status', StockPurchaseOrder::OPEN_STATUSES)->get();
        $poBy = ['accessory_price' => [], 'material' => [], 'supply' => []];
        foreach ($poOpen as $po) {
            if ($po->accessory_price_id) $poBy['accessory_price'][$po->accessory_price_id] = ($poBy['accessory_price'][$po->accessory_price_id] ?? 0) + $po->quantity;
            elseif ($po->material_id)    $poBy['material'][$po->material_id]   = ($poBy['material'][$po->material_id] ?? 0) + $po->quantity;
            elseif ($po->supply_id)      $poBy['supply'][$po->supply_id]       = ($poBy['supply'][$po->supply_id] ?? 0) + $po->quantity;
        }

        $rows = [];
        $mk = function ($kind, $id, $label, $used, $stock, $pending) use ($factor) {
            $monthly = (int) ceil($used * $factor);
            $have = $stock + $pending;
            return [
                'kind' => $kind, 'id' => $id, 'label' => $label,
                'used' => (int) $used, 'monthly_need' => $monthly,
                'stock' => (int) $stock, 'pending' => (int) $pending,
                'suggest' => max(0, $monthly - $have),
                'enough' => $have >= $monthly,
            ];
        };

        // Label = default-label supply; usage = number of shipped orders.
        $label = Supply::where('is_default_label', true)->first();
        if ($label) {
            $rows[] = $mk('label', $label->id, $label->name . ' (label)', $shippedCount,
                (int) $label->stock, $poBy['supply'][$label->id] ?? 0);
        }

        // Accessories — per (accessory_id, code) canonical, usage = Σ item qty.
        $accUse = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('order_item_accessories as oia', 'oia.order_item_id', '=', 'oi.id')
            ->join('accessory_prices as ap', 'ap.id', '=', 'oia.accessory_price_id')
            ->where('o.status', Order::STATUS_SHIPPED)->whereBetween('o.completed_time', [$since, $until])
            ->groupBy('ap.accessory_id', 'ap.accessory_code')
            ->selectRaw('ap.accessory_id, ap.accessory_code, SUM(oi.quantity) AS used')
            ->get();
        foreach ($accUse as $r) {
            $q = AccessoryPrice::with('accessory:id,name')->where('accessory_id', $r->accessory_id);
            $r->accessory_code === null ? $q->whereNull('accessory_code') : $q->where('accessory_code', $r->accessory_code);
            $canonical = $q->orderBy('id')->first();
            if (!$canonical) continue;
            $name = ($canonical->accessory?->name ?? 'Accessory') . ($r->accessory_code ? " [{$r->accessory_code}]" : '');
            $rows[] = $mk('accessory', $canonical->id, $name, $r->used, (int) $canonical->stock, $poBy['accessory_price'][$canonical->id] ?? 0);
        }

        // Materials — usage = Σ item qty grouped by material_id.
        $matUse = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNotNull('oi.material_id')
            ->where('o.status', Order::STATUS_SHIPPED)->whereBetween('o.completed_time', [$since, $until])
            ->groupBy('oi.material_id')
            ->selectRaw('oi.material_id, SUM(oi.quantity) AS used')
            ->pluck('used', 'material_id');
        foreach (Material::with('product:id,name')->get() as $m) {
            $used = (int) ($matUse[$m->id] ?? 0);
            $rows[] = $mk('material', $m->id, ($m->product?->name ? $m->product->name . ' · ' : '') . $m->name,
                $used, (int) $m->stock, $poBy['material'][$m->id] ?? 0);
        }

        return [
            'meta' => [
                'lookback_days'   => $lookback,
                'forecast_days'   => $cfg['forecast_days'],
                'orders_per_day'  => round($ordersPerDay, 2),
                'orders_per_day_auto' => $auto,
                'avg_per_day'     => round($avgPerDay, 2),
                'projected_orders'=> (int) ceil($projOrders),
                'shipped_orders'  => $shippedCount,
                'since'           => $sinceVn->toDateString(),
                'until'           => $untilVn->toDateString(),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Ask the LLM for a structured purchase plan (buy qty per item) so it can be
     * saved and shown on the Đặt hàng page, plus a short markdown summary. The
     * model is forced into a JSON shape (Anthropic tool-use / OpenAI json mode).
     */
    public function aiForecast(Request $request)
    {
        $cfg = $this->cfg();
        if ($cfg['api_key'] === '') {
            return response()->json(['message' => 'Chưa cấu hình AI API key (Settings → AI forecast).'], 422);
        }
        $data = $this->computeForecast();

        // ref = the order-items key (label → supply) so the plan maps 1:1.
        $refOf = fn ($r) => ($r['kind'] === 'label' ? 'supply' : $r['kind']) . '-' . $r['id'];
        $lines = array_map(
            fn ($r) => sprintf('- [ref:%s] %s: dùng %d/%d ngày, tồn %d, đã đặt %d, cần-dùng-kỳ %d',
                $refOf($r), $r['label'], $r['used'], $data['meta']['lookback_days'], $r['stock'], $r['pending'], $r['monthly_need']),
            $data['rows']
        );
        $prompt = "Bạn là trợ lý dự báo tồn kho. Dữ liệu {$data['meta']['lookback_days']} ngày qua "
            . "(chỉ đơn đã ship, {$data['meta']['shipped_orders']} đơn), ước chừng {$data['meta']['orders_per_day']} đơn/ngày, "
            . "dự báo {$data['meta']['forecast_days']} ngày tới (~{$data['meta']['projected_orders']} đơn):\n" . implode("\n", $lines)
            . "\n\nMỗi mục có 'cần-dùng-kỳ' = nhu cầu tiêu thụ trong kỳ dự báo. Hãy tính SỐ LƯỢNG NÊN NHẬP THÊM (buy) cho TỪNG mục "
            . "sao cho KHÔNG bị thiếu (xét tồn hiện có + đã đặt chờ về), và cộng thêm biên an toàn ~10–20% cho mục tiêu thụ cao hoặc đang âm tồn. "
            . "Mục đang dư đủ thì buy=0. Trả về cho TẤT CẢ các mục kèm đúng 'ref', mức 'priority' (high/medium/low), "
            . "và 'summary' tóm tắt ngắn tiếng Việt (markdown) nêu mục khẩn cấp + thứ tự ưu tiên.";

        try {
            $plan = $cfg['provider'] === 'openai'
                ? $this->callOpenAiPlan($cfg, $prompt)
                : $this->callAnthropicPlan($cfg, $prompt);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'AI call failed: ' . $e->getMessage()], 502);
        }

        // Normalize the plan into a ref → {buy, priority, note} map and persist it.
        $map = [];
        foreach ((is_array($plan['items'] ?? null) ? $plan['items'] : []) as $pi) {
            if (empty($pi['ref'])) continue;
            $map[$pi['ref']] = [
                'buy'      => max(0, (int) ($pi['buy'] ?? 0)),
                'priority' => $pi['priority'] ?? null,
                'note'     => $pi['note'] ?? null,
            ];
        }
        AppSetting::setJson('inventory_ai_suggestion', [
            'generated_at'   => now()->toIso8601String(),
            'model'          => $cfg['model'],
            'forecast_days'  => $data['meta']['forecast_days'],
            'orders_per_day' => $data['meta']['orders_per_day'],
            'items'          => $map,
        ]);

        return response()->json([
            'forecast' => $data,
            'ai_text'  => $plan['summary'] ?? '',
            'saved'    => count($map),
            'model'    => $cfg['model'],
        ]);
    }

    /** JSON schema shared by both providers for the purchase plan. */
    private function planProperties(): array
    {
        return [
            'summary' => ['type' => 'string', 'description' => 'Tóm tắt ngắn tiếng Việt (markdown): mục khẩn cấp + thứ tự ưu tiên.'],
            'items' => [
                'type' => 'array',
                'description' => 'Mỗi mục một dòng, gồm tất cả mục được cung cấp.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'ref'      => ['type' => 'string', 'description' => 'Mã mục, đúng như cung cấp (vd material-5).'],
                        'buy'      => ['type' => 'integer', 'description' => 'Số lượng nên nhập thêm (>=0).'],
                        'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        'note'     => ['type' => 'string'],
                    ],
                    'required' => ['ref', 'buy'],
                ],
            ],
        ];
    }

    private function callAnthropicPlan(array $cfg, string $prompt): array
    {
        $res = Http::withHeaders([
            'x-api-key' => $cfg['api_key'],
            'anthropic-version' => '2023-06-01',
        ])->timeout(90)->post('https://api.anthropic.com/v1/messages', [
            'model' => $cfg['model'],
            'max_tokens' => 4096,
            'tools' => [[
                'name' => 'submit_purchase_plan',
                'description' => 'Nộp kế hoạch nhập hàng: số lượng nên mua cho từng mục để không thiếu trong kỳ dự báo.',
                'input_schema' => ['type' => 'object', 'properties' => $this->planProperties(), 'required' => ['items']],
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'submit_purchase_plan'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        if (!$res->successful()) throw new \RuntimeException('HTTP ' . $res->status() . ' ' . $res->body());
        foreach ($res->json('content', []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'submit_purchase_plan') {
                return $block['input'] ?? [];
            }
        }
        throw new \RuntimeException('AI không trả về kế hoạch (tool_use).');
    }

    private function callOpenAiPlan(array $cfg, string $prompt): array
    {
        $res = Http::withToken($cfg['api_key'])->timeout(90)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $cfg['model'],
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => 'Trả về DUY NHẤT một JSON object đúng schema: '
                        . '{"summary": string, "items": [{"ref": string, "buy": integer, "priority": "high"|"medium"|"low", "note": string}]}. '
                        . 'Bao gồm tất cả mục được cung cấp.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        if (!$res->successful()) throw new \RuntimeException('HTTP ' . $res->status() . ' ' . $res->body());
        $parsed = json_decode($res->json('choices.0.message.content') ?? '{}', true);
        if (!is_array($parsed)) throw new \RuntimeException('AI trả về JSON không hợp lệ.');
        return $parsed;
    }

    // ── Đặt hàng: orderable items + reusable buy-link ─────────────────────

    /** All orderable stock items (material / accessory / supply) for Đặt hàng. */
    public function orderItems()
    {
        // Open (pending/approved) PO qty per target = "đã đặt chờ về".
        $poOpen = StockPurchaseOrder::whereIn('status', StockPurchaseOrder::OPEN_STATUSES)->get();
        $poBy = ['accessory_price' => [], 'material' => [], 'supply' => []];
        foreach ($poOpen as $po) {
            if ($po->accessory_price_id) $poBy['accessory_price'][$po->accessory_price_id] = ($poBy['accessory_price'][$po->accessory_price_id] ?? 0) + $po->quantity;
            elseif ($po->material_id)    $poBy['material'][$po->material_id]   = ($poBy['material'][$po->material_id] ?? 0) + $po->quantity;
            elseif ($po->supply_id)      $poBy['supply'][$po->supply_id]       = ($poBy['supply'][$po->supply_id] ?? 0) + $po->quantity;
        }

        // Forecast suggestion per item, keyed by "kind-id" (label → supply).
        $fc = $this->computeForecast();
        $fcBy = [];
        foreach ($fc['rows'] as $r) {
            $kind = $r['kind'] === 'label' ? 'supply' : $r['kind'];
            $fcBy[$kind . '-' . $r['id']] = ['suggest' => $r['suggest'], 'monthly_need' => $r['monthly_need'], 'used' => $r['used']];
        }

        // Saved AI plan (from "🤖 Hỏi AI" on the forecast page), keyed by ref.
        $aiSug = AppSetting::getJson('inventory_ai_suggestion', []);
        $aiItems = is_array($aiSug['items'] ?? null) ? $aiSug['items'] : [];

        $extraOf = function ($kind, $id) use ($fcBy, $aiItems) {
            $base = $fcBy["$kind-$id"] ?? ['suggest' => 0, 'monthly_need' => 0, 'used' => 0];
            $ai = $aiItems["$kind-$id"] ?? null;
            return $base + [
                'ai_suggest'  => $ai ? (int) $ai['buy'] : null,
                'ai_priority' => $ai['priority'] ?? null,
            ];
        };
        $fcOf = $extraOf;

        $items = [];

        foreach (Material::with('product:id,name')->orderBy('id')->get() as $m) {
            $items[] = [
                'kind' => 'material', 'id' => $m->id,
                'label' => ($m->product?->name ? $m->product->name . ' · ' : '') . $m->name,
                'stock' => (int) $m->stock, 'pending' => $poBy['material'][$m->id] ?? 0,
                'purchase_url' => $m->purchase_url,
            ] + $fcOf('material', $m->id);
        }

        // Accessories — canonical price row per (accessory_id, code); stock lives there.
        $seen = [];
        foreach (AccessoryPrice::with('accessory:id,name')->orderBy('id')->get() as $ap) {
            $key = $ap->accessory_id . '|' . ($ap->accessory_code ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $name = ($ap->accessory?->name ?? 'Accessory') . ($ap->accessory_code ? " [{$ap->accessory_code}]" : '');
            $items[] = [
                'kind' => 'accessory', 'id' => $ap->id, 'label' => $name,
                'stock' => (int) $ap->stock, 'pending' => $poBy['accessory_price'][$ap->id] ?? 0,
                'purchase_url' => $ap->purchase_url,
            ] + $fcOf('accessory', $ap->id);
        }

        foreach (Supply::orderBy('id')->get() as $s) {
            $items[] = [
                'kind' => 'supply', 'id' => $s->id,
                'label' => $s->name . ($s->is_default_label ? ' (label)' : ''),
                'stock' => (int) $s->stock, 'pending' => $poBy['supply'][$s->id] ?? 0,
                'purchase_url' => $s->purchase_url,
            ] + $fcOf('supply', $s->id);
        }

        return response()->json([
            'items' => $items,
            'meta'  => $fc['meta'],
            'ai_meta' => [
                'generated_at' => $aiSug['generated_at'] ?? null,
                'model'        => $aiSug['model'] ?? null,
                'forecast_days'=> $aiSug['forecast_days'] ?? null,
            ],
        ]);
    }

    /** Save the reusable "where to buy" link on a stock target. */
    public function saveItemLink(Request $request)
    {
        $data = $request->validate([
            'kind' => 'required|in:material,accessory,supply',
            'id'   => 'required|integer',
            'purchase_url' => 'nullable|string|max:1000',
        ]);
        $model = $this->targetModel($data['kind'], (int) $data['id']);
        if (!$model) return response()->json(['message' => 'Item not found'], 404);
        $model->update(['purchase_url' => $data['purchase_url'] ?: null]);
        return response()->json(['message' => 'Saved', 'purchase_url' => $model->purchase_url]);
    }

    private function targetModel(string $kind, int $id)
    {
        return match ($kind) {
            'material'  => Material::find($id),
            'accessory' => AccessoryPrice::find($id),
            'supply'    => Supply::find($id),
            default     => null,
        };
    }

    // ── Purchase orders ───────────────────────────────────────────────────
    public function poIndex(Request $request)
    {
        $q = StockPurchaseOrder::with([
            'productVariant.product:id,name', 'accessoryPrice.accessory:id,name',
            'material:id,name,product_id', 'material.product:id,name',
            'supply:id,name,code', 'creator:id,name', 'approver:id,name',
        ])->orderByDesc('id');
        if ($request->filled('status')) $q->where('status', $request->status);
        return response()->json($q->paginate($request->get('per_page', 30)));
    }

    public function poStore(Request $request)
    {
        $data = $request->validate([
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'accessory_price_id' => 'nullable|exists:accessory_prices,id',
            'material_id'        => 'nullable|exists:materials,id',
            'supply_id'          => 'nullable|exists:supplies,id',
            'price_mode'         => 'nullable|in:per_item,per_package',
            'quantity'           => 'nullable|integer|min:1',
            'package_size'       => 'nullable|integer|min:1',
            'package_count'      => 'nullable|integer|min:1',
            'unit_price'         => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string|max:500',
            'purchase_url'       => 'nullable|string|max:1000',
        ]);
        $purchaseUrl = array_key_exists('purchase_url', $data) ? $data['purchase_url'] : null;
        unset($data['purchase_url']);

        $targets = ($data['product_variant_id'] ?? null ? 1 : 0) + ($data['accessory_price_id'] ?? null ? 1 : 0)
            + ($data['material_id'] ?? null ? 1 : 0) + ($data['supply_id'] ?? null ? 1 : 0);
        if ($targets !== 1) {
            return response()->json(['message' => 'Provide exactly one target'], 422);
        }

        // Resolve quantity (total units) + total_cost from the price mode.
        $mode = $data['price_mode'] ?? StockPurchaseOrder::MODE_PER_ITEM;
        $unitPrice = $data['unit_price'] ?? null;
        if ($mode === StockPurchaseOrder::MODE_PER_PACKAGE) {
            if (empty($data['package_size']) || empty($data['package_count'])) {
                return response()->json(['message' => 'package_size và package_count bắt buộc khi đặt theo package'], 422);
            }
            $data['quantity']   = (int) $data['package_size'] * (int) $data['package_count'];
            $data['total_cost'] = $unitPrice !== null ? round((float) $unitPrice * (int) $data['package_count'], 2) : null;
        } else {
            if (empty($data['quantity'])) {
                return response()->json(['message' => 'quantity bắt buộc khi đặt theo cái'], 422);
            }
            $data['package_size']  = null;
            $data['package_count'] = null;
            $data['total_cost']    = $unitPrice !== null ? round((float) $unitPrice * (int) $data['quantity'], 2) : null;
        }
        $data['price_mode'] = $mode;

        $po = StockPurchaseOrder::create($data + [
            'status' => StockPurchaseOrder::STATUS_PENDING,
            'created_by_user_id' => $request->user()->id,
        ]);

        // Persist the reusable buy-link on the target item so the next reorder pre-fills it.
        if ($purchaseUrl !== null) {
            $url = $purchaseUrl ?: null;
            if ($data['accessory_price_id'] ?? null) AccessoryPrice::where('id', $data['accessory_price_id'])->update(['purchase_url' => $url]);
            elseif ($data['material_id'] ?? null)    Material::where('id', $data['material_id'])->update(['purchase_url' => $url]);
            elseif ($data['supply_id'] ?? null)      Supply::where('id', $data['supply_id'])->update(['purchase_url' => $url]);
        }

        return response()->json(['message' => 'Purchase order created', 'po' => $po], 201);
    }

    public function poApprove(Request $request, StockPurchaseOrder $po)
    {
        if ($po->status !== StockPurchaseOrder::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending POs can be approved'], 422);
        }
        $po->update(['status' => StockPurchaseOrder::STATUS_APPROVED, 'approved_by_user_id' => $request->user()->id]);
        return response()->json(['message' => 'Approved', 'po' => $po]);
    }

    /** Mark received → add to stock (idempotent). */
    public function poReceive(StockPurchaseOrder $po)
    {
        if ($po->status === StockPurchaseOrder::STATUS_RECEIVED) {
            return response()->json(['message' => 'Already received', 'po' => $po]);
        }
        if ($po->status === StockPurchaseOrder::STATUS_CANCELLED) {
            return response()->json(['message' => 'Cannot receive a cancelled PO'], 422);
        }
        DB::transaction(function () use ($po) {
            if ($po->product_variant_id) ProductVariant::where('id', $po->product_variant_id)->increment('stock', $po->quantity);
            elseif ($po->accessory_price_id) AccessoryPrice::where('id', $po->accessory_price_id)->increment('stock', $po->quantity);
            elseif ($po->material_id) Material::where('id', $po->material_id)->increment('stock', $po->quantity);
            elseif ($po->supply_id) Supply::where('id', $po->supply_id)->increment('stock', $po->quantity);
            $po->update(['status' => StockPurchaseOrder::STATUS_RECEIVED, 'received_at' => now()]);
        });
        return response()->json(['message' => 'Received; stock added', 'po' => $po->fresh()]);
    }

    public function poDestroy(StockPurchaseOrder $po)
    {
        // Received POs already added stock — don't silently reverse; just cancel.
        if ($po->status === StockPurchaseOrder::STATUS_RECEIVED) {
            return response()->json(['message' => 'Cannot delete a received PO'], 422);
        }
        $po->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
