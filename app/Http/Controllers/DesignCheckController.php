<?php

namespace App\Http\Controllers;

use App\Models\AccessoryPrice;
use App\Models\Order;
use App\Models\OrderDesignCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Card-skin design check: does the artwork match the reference size, and does
 * it carry a chip cutout the right size for the chip ordered?
 *
 * The measuring happens in the desktop app — only it can decode the image —
 * so this side just hands out the work queue (`pending`), stores verdicts
 * (`store`) and serves the report (`index`).
 */
class DesignCheckController extends Controller
{
    /** Meta keys whose artwork is worth measuring. */
    private const FIELDS = ['front', 'back'];

    /**
     * Items that still need measuring: card-skin orders in the requested
     * fulfill statuses whose result is missing or was produced by an older
     * checker.
     */
    public function pending(Request $request)
    {
        $request->validate([
            'statuses'   => 'nullable|array',
            'statuses.*' => 'integer|min:0|max:7',
            'version'    => 'nullable|string|max:8',
            'limit'      => 'nullable|integer|min:1|max:2000',
            'recheck'    => 'nullable|boolean',
        ]);

        $statuses = array_map('intval', (array) $request->input('statuses', []));
        if (empty($statuses)) {
            // Mặc định: mọi trạng thái chưa ship/huỷ — không quét đơn đã xong.
            $statuses = [
                Order::STATUS_NEW_ORDER, Order::STATUS_PROCESSING, Order::STATUS_WRONGSIZE,
                Order::STATUS_FIXED, Order::STATUS_REPRINT, Order::STATUS_ONHOLD,
            ];
        }
        $version = (string) $request->input('version', '');
        $recheck = $request->boolean('recheck');

        // Which order_types are card skin, straight from Settings.
        $layouts = (array) \App\Models\AppSetting::getJson('convert_layouts', []);
        $cardTypes = array_keys(array_filter($layouts, fn ($v) => $v === 'outside'));
        if (empty($cardTypes)) {
            return response()->json(['items' => [], 'message' => 'No order_type is mapped to the "outside" (card skin) layout']);
        }

        $orders = Order::with([
            'items.metas',
            'items.productVariant.product:id,name,line_id,order_type',
            'items.accessoryPrice',
            'items.accessoryPrices',
        ])
            ->whereIn('status', $statuses)
            ->whereHas('items.productVariant.product', fn ($q) => $q->whereIn('order_type', $cardTypes))
            ->orderByDesc('id')
            ->limit((int) $request->input('limit', 500))
            ->get();

        $done = OrderDesignCheck::whereIn('order_id', $orders->pluck('id'))->get()
            ->keyBy(fn ($c) => $c->order_item_id . '|' . $c->field);

        $items = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if (($item->productVariant->product->order_type ?? null) === null) continue;
                if (!in_array($item->productVariant->product->order_type, $cardTypes, true)) continue;

                foreach (self::FIELDS as $field) {
                    $meta = $item->metas->firstWhere('key', $field);
                    if (!$meta || empty($meta->value)) continue;

                    $prev = $done->get($item->id . '|' . $field);
                    if (!$recheck && $prev
                        && $prev->url === $meta->value
                        && ($version === '' || $prev->checker_version === $version)) {
                        continue;   // đã đo đúng URL này bằng đúng phiên bản
                    }

                    $items[] = [
                        'order_id'      => $order->id,
                        'system_id'     => $order->system_id,
                        'status'        => $order->status,
                        'order_item_id' => $item->id,
                        'field'         => $field,
                        'url'           => $meta->value,
                        'chip'          => $this->itemChipKind($item) ?: 'nochip',
                    ];
                }
            }
        }

        return response()->json(['items' => $items, 'count' => count($items)]);
    }

    /** Store the verdicts the app measured for one order. */
    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'version'                 => 'nullable|string|max:8',
            'results'                 => 'present|array',
            'results.*.order_item_id' => 'required|integer',
            'results.*.field'         => 'required|string|max:32',
            'results.*.url'           => 'nullable|string',
            'results.*.chip'          => 'nullable|string|max:16',
            'results.*.status'        => 'required|string|in:ok,fail,error',
            'results.*.category'      => 'nullable|string|in:size,hole,both',
            'results.*.reason'        => 'nullable|string|max:191',
            'results.*.width'         => 'nullable|integer|min:0',
            'results.*.height'        => 'nullable|integer|min:0',
            'results.*.hole_w'        => 'nullable|integer|min:0',
            'results.*.hole_h'        => 'nullable|integer|min:0',
        ]);

        $itemIds = $order->items()->pluck('id')->all();
        $hasFail = collect($data['results'])->contains(fn ($r) => $r['status'] === 'fail');

        DB::transaction(function () use ($data, $order, $itemIds, $hasFail) {
            foreach ($data['results'] as $r) {
                if (!in_array((int) $r['order_item_id'], $itemIds, true)) continue;   // không thuộc đơn này
                OrderDesignCheck::updateOrCreate(
                    ['order_item_id' => (int) $r['order_item_id'], 'field' => $r['field']],
                    [
                        'order_id'        => $order->id,
                        'url'             => $r['url'] ?? null,
                        'chip'            => $r['chip'] ?? null,
                        'status'          => $r['status'],
                        'category'        => $r['category'] ?? null,
                        'reason'          => $r['reason'] ?? null,
                        'width'           => $r['width'] ?? null,
                        'height'          => $r['height'] ?? null,
                        'hole_w'          => $r['hole_w'] ?? null,
                        'hole_h'          => $r['hole_h'] ?? null,
                        'checker_version' => $data['version'] ?? null,
                        'checked_at'      => now(),
                    ],
                );
            }

            // Thiết kế không đạt → đẩy đơn về wrongsize để xưởng không in tiếp.
            // Chỉ đụng các trạng thái còn đang chạy: đơn đã ship/huỷ, hoặc đã
            // nằm sẵn ở wrongsize, thì để nguyên.
            $movable = [
                Order::STATUS_NEW_ORDER, Order::STATUS_PROCESSING,
                Order::STATUS_FIXED, Order::STATUS_REPRINT, Order::STATUS_ONHOLD,
            ];
            if ($hasFail && in_array($order->status, $movable, true)) {
                $order->update(['status' => Order::STATUS_WRONGSIZE]);
            }
        });

        return response()->json([
            'message'          => 'Saved ' . count($data['results']) . ' result(s)',
            'moved_wrongsize'  => $hasFail && $order->fresh()->status === Order::STATUS_WRONGSIZE,
        ]);
    }

    /** Report: newest first, filterable by fulfill status and verdict. */
    public function index(Request $request)
    {
        $request->validate([
            'statuses'   => 'nullable|array',
            'statuses.*' => 'integer|min:0|max:7',
            'status'     => 'nullable|string|in:ok,fail,error',
            'per_page'   => 'nullable|integer|min:1|max:200',
        ]);

        $query = OrderDesignCheck::with(['order:id,system_id,status,user_id', 'order.user:id,name'])
            ->orderByDesc('checked_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('statuses')) {
            $ids = array_map('intval', (array) $request->statuses);
            $query->whereHas('order', fn ($q) => $q->whereIn('status', $ids));
        }

        $paginated = $query->paginate((int) $request->input('per_page', 50));

        return response()->json($paginated);
    }

    /**
     * Bắn Telegram cho các lỗi CHƯA báo, gom theo từng seller rồi theo từng
     * loại lỗi, liệt kê ref_id. Cron gọi sau mỗi lượt quét; `notified_at` đảm
     * bảo chạy lại không báo trùng.
     */
    public function notify(Request $request, \App\Services\TelegramService $telegram)
    {
        $pending = OrderDesignCheck::with(['order:id,system_id,ref_id,status,user_id', 'order.user:id,name,telegram_chat_id,telegram_notifications_enabled'])
            ->where('status', 'fail')
            ->whereNull('notified_at')
            ->orderBy('order_id')
            ->get()
            ->filter(fn ($c) => $c->order !== null);

        if ($pending->isEmpty()) {
            return response()->json(['message' => 'Nothing to notify', 'sent' => 0]);
        }

        $labels = [
            'hole' => '🕳 Thiếu / sai lỗ khoét chip',
            'size' => '📐 Sai kích thước',
            'both' => '⚠️ Sai cả kích thước lẫn lỗ khoét',
        ];

        $sent = 0;
        foreach ($pending->groupBy(fn ($c) => $c->order->user_id) as $userId => $checks) {
            $owner = $checks->first()->order->user;
            $lines = [
                '🚫 <b>Design không đạt — đã chuyển sang wrongsize</b>',
                'Seller: ' . e($owner->name ?? ("#{$userId}")),
                '',
            ];

            foreach ($checks->groupBy(fn ($c) => $c->category ?: 'other') as $cat => $group) {
                // Một đơn có thể hỏng nhiều item — ref_id chỉ liệt kê một lần.
                $refs = $group->map(fn ($c) => $c->order->ref_id ?: $c->order->system_id)
                    ->unique()->values();
                $lines[] = sprintf('%s — <b>%d</b> đơn', $labels[$cat] ?? '❓ Lỗi khác', $refs->count());
                $lines[] = e($refs->implode(', '));
                $lines[] = '';
            }

            $text = trim(implode("
", $lines));

            if (!empty($owner->telegram_chat_id) && $owner->telegram_notifications_enabled) {
                try { $telegram->sendMessage($owner->telegram_chat_id, $text); $sent++; }
                catch (\Throwable $e) { \Log::warning('[design-check] telegram seller: ' . $e->getMessage()); }
            }

            // Bản sao cho nhóm vận hành. Ưu tiên đích riêng của design-check
            // (topic "Wrongsize"); chưa cấu hình thì rơi về kênh admin mặc định.
            $cfg = \App\Services\TelegramService::settings();
            $groupChat = $cfg['design_check_chat_id'] ?: ($cfg['default_chat_id'] ?? null);
            $groupThread = $cfg['design_check_chat_id']
                ? ($cfg['design_check_thread_id'] ?? null)
                : ($cfg['default_thread_id'] ?? null);
            if ($groupChat && (string) $groupChat !== (string) ($owner->telegram_chat_id ?? '')) {
                try { $telegram->sendMessage($groupChat, $text, $groupThread); $sent++; }
                catch (\Throwable $e) { \Log::warning('[design-check] telegram group: ' . $e->getMessage()); }
            }
        }

        OrderDesignCheck::whereIn('id', $pending->pluck('id'))->update(['notified_at' => now()]);

        return response()->json([
            'message' => "Notified {$pending->count()} failure(s) across " . $pending->groupBy(fn ($c) => $c->order->user_id)->count() . ' seller(s)',
            'sent'    => $sent,
        ]);
    }

    /**
     * Gate 2 → 3: the app punched the hole and uploaded the result. Park the
     * URL on the check row so it survives a reload and whoever approves next
     * can see it. The live design is NOT touched yet.
     */
    public function punched(Request $request, OrderDesignCheck $designCheck)
    {
        $data = $request->validate(['punched_url' => 'required|url']);

        $designCheck->update([
            'punched_url' => $data['punched_url'],
            'punched_at'  => now(),
            'applied_at'  => null,   // bản đục mới → cần duyệt lại
        ]);

        return response()->json(['message' => 'Punched file saved for review', 'check' => $designCheck->fresh()]);
    }

    /**
     * Gate 3: reviewed and accepted — swap the design link for the punched one.
     * The _qr metas built from the old artwork are dropped so the converter
     * rebuilds them, otherwise the press would keep printing the old file.
     */
    public function apply(Request $request, OrderDesignCheck $designCheck)
    {
        $request->validate(['reconvert' => 'nullable|boolean']);
        $reconvert = $request->boolean('reconvert', true);

        if (empty($designCheck->punched_url)) {
            return response()->json(['message' => 'Nothing punched to apply'], 422);
        }

        $result = DB::transaction(function () use ($designCheck, $reconvert) {
            \App\Models\OrderItemMeta::updateOrCreate(
                ['order_item_id' => $designCheck->order_item_id, 'key' => $designCheck->field],
                ['value' => $designCheck->punched_url],
            );

            $deleted = 0;
            if ($reconvert) {
                $deleted = \App\Models\OrderItemMeta::where('order_item_id', $designCheck->order_item_id)
                    ->where('key', 'regexp', '_qr(_[0-9]+)?$')
                    ->delete();
                Order::whereKey($designCheck->order_id)->update(['production' => false]);
            }

            // Bản vá đã vào chỗ — kết quả check cũ không còn đúng nữa, đánh dấu
            // để lượt cron sau đo lại chính file mới này.
            $designCheck->update([
                'applied_at'      => now(),
                'url'             => $designCheck->punched_url,
                'status'          => 'pending',
                'checker_version' => null,
            ]);

            return $deleted;
        });

        return response()->json([
            'message'    => 'Design replaced' . ($reconvert ? "; removed {$result} _qr meta(s)" : ''),
            'qr_deleted' => $result,
        ]);
    }

    /** Chip of an item — multi pivot first, then the legacy single. */
    private function itemChipKind($item): string
    {
        foreach ($item->accessoryPrices as $ap) {
            $kind = $ap->chipKindOfSelf();
            if ($kind !== '') return $kind;
        }

        return $item->accessoryPrice?->chipKindOfSelf() ?? '';
    }
}
