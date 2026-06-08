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

    private function computeForecast(): array
    {
        $cfg = $this->cfg();
        $lookback = max(1, $cfg['lookback_days']);
        $since = now()->subDays($lookback);

        $shippedCount = (int) Order::where('status', Order::STATUS_SHIPPED)
            ->where('completed_time', '>=', $since)->count();

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
            ->where('o.status', Order::STATUS_SHIPPED)->where('o.completed_time', '>=', $since)
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
            ->where('o.status', Order::STATUS_SHIPPED)->where('o.completed_time', '>=', $since)
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
                'since'           => $since->toDateString(),
            ],
            'rows' => $rows,
        ];
    }

    /** Send the forecast data to the configured LLM and return its prediction. */
    public function aiForecast(Request $request)
    {
        $cfg = $this->cfg();
        if ($cfg['api_key'] === '') {
            return response()->json(['message' => 'Chưa cấu hình AI API key (Settings → AI forecast).'], 422);
        }
        $data = $this->computeForecast();

        $lines = array_map(
            fn ($r) => sprintf('- %s [%s]: dùng %d/%dngày, tồn %d, đã đặt %d, tồn-tối-thiểu-tháng %d',
                $r['label'], $r['kind'], $r['used'], $data['meta']['lookback_days'], $r['stock'], $r['pending'], $r['monthly_need']),
            $data['rows']
        );
        $prompt = "Bạn là trợ lý dự báo tồn kho. Dữ liệu tiêu thụ {$data['meta']['lookback_days']} ngày qua "
            . "(chỉ đơn đã ship, {$data['meta']['shipped_orders']} đơn), ước chừng {$data['meta']['orders_per_day']} đơn/ngày "
            . "(~{$data['meta']['projected_orders']} đơn trong kỳ), dự báo cho {$data['meta']['forecast_days']} ngày tới:\n" . implode("\n", $lines)
            . "\n\nHãy dự đoán số lượng nên nhập thêm cho mỗi mục trong 1 tháng tới (xét tồn hiện có + đã đặt chờ về), "
            . "chỉ rõ mục nào sắp thiếu và mức ưu tiên mua. Trả lời ngắn gọn tiếng Việt, dạng danh sách.";

        try {
            $text = $cfg['provider'] === 'openai'
                ? $this->callOpenAi($cfg, $prompt)
                : $this->callAnthropic($cfg, $prompt);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'AI call failed: ' . $e->getMessage()], 502);
        }

        return response()->json(['forecast' => $data, 'ai_text' => $text, 'model' => $cfg['model']]);
    }

    private function callAnthropic(array $cfg, string $prompt): string
    {
        $res = Http::withHeaders([
            'x-api-key' => $cfg['api_key'],
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => $cfg['model'],
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        if (!$res->successful()) throw new \RuntimeException('HTTP ' . $res->status() . ' ' . $res->body());
        return $res->json('content.0.text') ?? '';
    }

    private function callOpenAi(array $cfg, string $prompt): string
    {
        $res = Http::withToken($cfg['api_key'])->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $cfg['model'],
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        if (!$res->successful()) throw new \RuntimeException('HTTP ' . $res->status() . ' ' . $res->body());
        return $res->json('choices.0.message.content') ?? '';
    }

    // ── Purchase orders ───────────────────────────────────────────────────
    public function poIndex(Request $request)
    {
        $q = StockPurchaseOrder::with([
            'productVariant.product:id,name', 'accessoryPrice.accessory:id,name',
            'material:id,name', 'supply:id,name,code', 'creator:id,name', 'approver:id,name',
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
            'quantity'           => 'required|integer|min:1',
            'unit_price'         => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string|max:500',
        ]);
        $targets = ($data['product_variant_id'] ?? null ? 1 : 0) + ($data['accessory_price_id'] ?? null ? 1 : 0)
            + ($data['material_id'] ?? null ? 1 : 0) + ($data['supply_id'] ?? null ? 1 : 0);
        if ($targets !== 1) {
            return response()->json(['message' => 'Provide exactly one target'], 422);
        }
        $po = StockPurchaseOrder::create($data + [
            'status' => StockPurchaseOrder::STATUS_PENDING,
            'created_by_user_id' => $request->user()->id,
        ]);
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
