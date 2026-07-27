<?php

namespace App\Http\Controllers;

use App\Models\AccessoryPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMeta;
use App\Models\Address;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ImageValidationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * @OA\Get(
     *     path="/orders",
     *     tags={"Orders"},
     *     summary="List orders",
     *     description="Paginated list. Seller chỉ thấy đơn của mình. Product filters match nếu đơn có **ít nhất một** order_item thỏa điều kiện. Load filter options qua GET /products và GET /variants.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status",              in="query", description="Status 0–7 (6=shipped)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="ship_type",           in="query", @OA\Schema(type="string", enum={"tiktok_ship","seller_ship"})),
     *     @OA\Parameter(name="ref_id",              in="query", description="Contains match", @OA\Schema(type="string")),
     *     @OA\Parameter(name="ref_ids",             in="query", description="Comma/newline separated exact ref_id list", @OA\Schema(type="string")),
     *     @OA\Parameter(name="system_id",           in="query", description="Contains match", @OA\Schema(type="string")),
     *     @OA\Parameter(name="tracking_id",         in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="tracking_status",     in="query", description="ShipEngine status, or `none` = chưa check", @OA\Schema(type="string")),
     *     @OA\Parameter(name="date_from",           in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to",             in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="paid",                in="query", @OA\Schema(type="string", enum={"paid","unpaid"})),
     *     @OA\Parameter(name="user_id",             in="query", description="Admin/support only — filter by owner", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="product_id",          in="query", description="orders có item thuộc product này", @OA\Schema(type="integer"), example=5),
     *     @OA\Parameter(name="product_ids",         in="query", description="Multi product — repeat param or comma list", @OA\Schema(type="string"), example="5,8"),
     *     @OA\Parameter(name="product_variant_id",  in="query", description="Exact variant/SKU row id", @OA\Schema(type="integer"), example=2),
     *     @OA\Parameter(name="line_id",             in="query", description="products.line_id exact (GC, PS_C…)", @OA\Schema(type="string"), example="GC"),
     *     @OA\Parameter(name="sku",                 in="query", description="product_variants.sku exact", @OA\Schema(type="string"), example="GE"),
     *     @OA\Parameter(name="color",               in="query", description="product_variants.color exact", @OA\Schema(type="string")),
     *     @OA\Parameter(name="size",                in="query", description="product_variants.size exact", @OA\Schema(type="string")),
     *     @OA\Parameter(name="paper_type",          in="query", description="product_variants.paper_type exact", @OA\Schema(type="string")),
     *     @OA\Parameter(name="material_id",         in="query", description="order_items.material_id", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="accessory_id",        in="query", description="orders có item dùng accessory này", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="accessory_code",      in="query", description="Exact match trên accessory_prices.accessory_code (e.g. W, R, B). Kết hợp được với accessory_id.", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort_by",             in="query", @OA\Schema(type="string", default="id")),
     *     @OA\Parameter(name="sort_dir",            in="query", @OA\Schema(type="string", enum={"asc","desc"}, default="desc")),
     *     @OA\Parameter(name="page",                in="query", @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="per_page",            in="query", @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Paginated list")
     * )
     */
    public function index(Request $request)
    {
        $hideQr = $this->shouldHideQrMetas($request->user());
        $metasRel = $hideQr
            ? ['items.metas' => fn ($q) => $q->where('key', 'not regexp', '_qr(_[0-9]+)?$')]
            : ['items.metas'];

        $query = Order::with(array_merge($metasRel, ['items.productVariant.product', 'items.material', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'items.accessoryPrices.accessory', 'items.accessoryPrices.tier', 'address', 'user']));

        // Delivery tracking state (skip the heavy `raw` JSON column).
        $query->with(['tracking' => fn ($q) => $q->select(
            'id', 'order_id', 'tracking_id', 'status', 'status_description',
            'date_intransit', 'delivered_at', 'last_event_at', 'checked_at'
        )]);

        // Seller chỉ thấy đơn của mình
        if ($request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user()->id);
        }

        // Filter by user_id (admin/support)
        if ($request->has('user_id') && !$request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user_id);
        }

        $this->applyOrderListFilters($query, $request);

        // Sort
        $sortBy = $request->get('sort_by', 'id');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $orders = $query->paginate($request->get('per_page', 20));
        if ($hideQr) {
            $orders->getCollection()->each(fn ($o) => $o->makeHidden('production'));
        } else {
            // admin/support can read convert_label (hidden by default on the model)
            $orders->getCollection()->each(fn ($o) => $o->makeVisible('convert_label'));
        }

        // Flag duplicates by lookup against the cached `duplicate_ref_ids` table.
        // The table is refreshed by the scheduled `orders:refresh-duplicates`
        // command (every 5 min) and inline after bulk write paths so flags are
        // close to real-time without recomputing on every list query.
        $pageItems = $orders->getCollection();
        $pageRefs = $pageItems->pluck('ref_id')->filter(fn ($r) => $r !== null && $r !== '')->unique()->values();
        $pageUserIds = $pageItems->pluck('user_id')->unique()->values();
        $dupSet = [];
        if ($pageRefs->isNotEmpty() && $pageUserIds->isNotEmpty()) {
            $dupRows = \DB::table('duplicate_ref_ids')
                ->whereIn('user_id', $pageUserIds)
                ->whereIn('ref_id', $pageRefs)
                ->get(['user_id', 'ref_id']);
            foreach ($dupRows as $r) {
                $dupSet["{$r->user_id}|{$r->ref_id}"] = true;
            }
        }
        $pageItems->each(function ($o) use ($dupSet) {
            $o->is_duplicate_ref = $o->ref_id !== null && isset($dupSet["{$o->user_id}|{$o->ref_id}"]);
        });

        return response()->json($orders);
    }

    /**
     * @OA\Get(
     *     path="/orders/{order}",
     *     tags={"Orders"},
     *     summary="Get order",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="order", in="path", required=true, @OA\Schema(type="integer"), example=123),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    /**
     * GET /api/orders/{order}/convert-label-blob — server-side fetch of the
     * order's convert_label image. Returns raw image bytes with the original
     * content-type, so the Electron renderer can embed it in a merged PDF
     * without browser CORS issues against B2.
     *
     * Auth: any logged-in user who can view the order. Sellers limited to
     * their own (same scoping as show()).
     */
    public function convertLabelBlob(Request $request, Order $order)
    {
        $user = $request->user();
        if ($user?->hasRole('seller') && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (empty($order->convert_label)) {
            return response()->json(['message' => 'Order has no convert_label'], 404);
        }

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(15)->get($order->convert_label);
            if (!$res->ok()) {
                return response()->json([
                    'message' => "Upstream returned HTTP {$res->status()}",
                ], 502);
            }
            return response($res->body(), 200, [
                'Content-Type'  => $res->header('Content-Type') ?: 'image/jpeg',
                'Cache-Control' => 'public, max-age=300',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function show(Request $request, Order $order)
    {
        $hideQr = $this->shouldHideQrMetas($request->user());
        $metasRel = $hideQr
            ? ['items.metas' => fn ($q) => $q->where('key', 'not regexp', '_qr(_[0-9]+)?$')]
            : ['items.metas'];

        $order->load(array_merge($metasRel, ['items.productVariant.product', 'items.material', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'items.accessoryPrices.accessory', 'items.accessoryPrices.tier', 'address', 'user']));
        if ($hideQr) {
            $order->makeHidden('production');
        } else {
            $order->makeVisible('convert_label');
        }

        return response()->json(['order' => $order]);
    }

    private function shouldHideQrMetas($user): bool
    {
        if (!$user || !$user->role) return true;
        return !in_array($user->role->slug, ['admin', 'support']);
    }

    /**
     * Pick a ref_id that doesn't yet exist for this user, given a base ref_id
     * and an in-progress map of ref_ids already assigned in the current batch.
     * Appends `_r_1`, `_r_2`, … until a free slot is found.
     */
    private function generateUniqueRefId($userId, ?string $baseRefId, array &$assignedInBatch, ?int $excludeOrderId = null): ?string
    {
        if ($baseRefId === null || $baseRefId === '') return null;

        $existsForUser = function (string $candidate) use ($userId, $excludeOrderId) {
            $q = Order::where('user_id', $userId)->where('ref_id', $candidate);
            if ($excludeOrderId) $q->where('id', '!=', $excludeOrderId);
            return $q->exists();
        };

        if (!isset($assignedInBatch[$baseRefId]) && !$existsForUser($baseRefId)) {
            return $baseRefId;
        }
        $n = 1;
        while (true) {
            $candidate = $baseRefId . '_r_' . $n;
            if (!isset($assignedInBatch[$candidate]) && !$existsForUser($candidate)) {
                return $candidate;
            }
            $n++;
        }
    }

    /**
     * Normalize accessory ids from a request item payload. Accepts either
     * the legacy single `accessory_item_id` or the new `accessory_ids[]`
     * array; returns a deduped int array preserving order.
     */
    private function collectAccessoryIds(array $itemData): array
    {
        $ids = [];
        if (!empty($itemData['accessory_ids']) && is_array($itemData['accessory_ids'])) {
            foreach ($itemData['accessory_ids'] as $id) {
                $i = (int) $id;
                if ($i > 0) $ids[$i] = true;
            }
        }
        if (!empty($itemData['accessory_item_id'])) {
            $i = (int) $itemData['accessory_item_id'];
            if ($i > 0) $ids[$i] = true;
        }
        return array_keys($ids);
    }

    /**
     * Attach the given accessory ids to the item via the pivot, filtering
     * down to the user's tier so a seller can't trick the form into using
     * another tier's price. Also updates the legacy primary
     * accessory_item_id column to the first valid id so single-accessory
     * read paths (Conversion / QR) keep working. Returns the per-unit
     * accessory total (sum of prices) for inclusion in print_cost.
     */
    private function syncItemAccessories(OrderItem $item, array $accessoryIds, ?int $tierId): float
    {
        // Snapshot the current add-on set so we can tell if it actually changes
        // below — a real change must invalidate this item's *_qr overlays.
        $before = $item->accessoryPrices()->pluck('accessory_prices.id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all();

        $total = 0.0;
        if (empty($accessoryIds) || $tierId === null) {
            $item->accessoryPrices()->sync([]);
            if ($item->accessory_item_id !== null) {
                $item->update(['accessory_item_id' => null]);
            }
        } else {
            $valid = AccessoryPrice::whereIn('id', $accessoryIds)
                ->where('tier_id', $tierId)
                ->get()
                ->keyBy('id');
            // Preserve the caller's order so accessory_item_id maps to the first
            // accessory the user actually picked, not the lowest id.
            $orderedValid = collect($accessoryIds)
                ->map(fn ($id) => $valid->get((int) $id))
                ->filter()
                ->values();
            if ($orderedValid->isEmpty()) {
                $item->accessoryPrices()->sync([]);
                $item->update(['accessory_item_id' => null]);
            } else {
                $ids = $orderedValid->pluck('id')->all();
                $item->accessoryPrices()->sync($ids);
                $item->update(['accessory_item_id' => $ids[0]]);
                $total = (float) $orderedValid->sum('price');
            }
        }

        // A changed add-on set alters the barcode band text (system_id-{addon})
        // on EVERY overlay, so drop all this item's *_qr metas and let the
        // converter rebuild them with the new add-on. Mirrors the design-meta
        // edit path; a no-op on initial create (no _qr metas exist yet).
        $after = $item->accessoryPrices()->pluck('accessory_prices.id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($before !== $after) {
            OrderItemMeta::where('order_item_id', $item->id)
                ->where('key', 'regexp', '_qr(_[0-9]+)?$')
                ->delete();
        }

        return $total;
    }

    /**
     * @OA\Post(
     *     path="/orders",
     *     tags={"Orders"},
     *     summary="Create order (Sanctum)",
     *     description="Tạo 1 đơn mới. Phải gửi 1 trong 2: `shipping_label` URL HOẶC `address` đầy đủ. Items hỗ trợ multi-accessory qua `accessory_ids[]`.

### `items[].metas` keys

Mảng `metas` chứa các artwork URLs theo từng mặt in. Mỗi entry là `{ key, value }` — `value` là URL ảnh design (Drive/B2/direct image), `key` là tên mặt in. Các key cho phép:

| key | Mặt in | Khi nào dùng |
|-----|--------|--------------|
| `front` | Mặt trước | Đa số sản phẩm 1 hoặc 2 mặt. Thường bắt buộc. |
| `back` | Mặt sau | Đơn 2 mặt. Tự cộng thêm `2nd_fee` vào item price (vd \$0.50). |
| `left` | Mặt trái | Đơn có 3 mặt+ (vd áo có in tay trái). |
| `right` | Mặt phải | Tương tự `left`. |
| `neck` | Phần cổ | Thường cho áo / pass sleeve cần in tag cổ. |
| `special` | Mặt đặc biệt | Mọi case khác — packer xem hint trong gangsheet. |

**Pricing rule:** `item.price = base_cost + (N − 1) × 2nd_fee` với N là số meta key có giá trị (counted distinct). Variant prices lấy theo `(product_variant_id, tier_id)` từ table `product_variant_prices`.

**Bỏ qua meta key trống** — backend filter mọi entry có `value` rỗng trước khi save.

**Không truyền `*_qr` key** — đó là các meta server tự sinh sau khi convert (overlay barcode), không nhận từ client.",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"ref_id","items"},
     *             @OA\Property(property="ref_id", type="string", example="TIKTOK-1234567", description="ID đơn nội bộ (unique per seller)"),
     *             @OA\Property(property="shipping_label", type="string", example="https://drive.google.com/file/d/ABC/view", description="URL carrier label. Nếu có thì không cần address."),
     *             @OA\Property(
     *                 property="address", type="object", description="Bắt buộc nếu KHÔNG có shipping_label.",
     *                 @OA\Property(property="first_name", type="string", example="John"),
     *                 @OA\Property(property="last_name",  type="string", example="Doe"),
     *                 @OA\Property(property="address_1",  type="string", example="123 Main St"),
     *                 @OA\Property(property="address_2",  type="string", example="Apt 5"),
     *                 @OA\Property(property="city",       type="string", example="Boston"),
     *                 @OA\Property(property="state",      type="string", example="MA"),
     *                 @OA\Property(property="zipcode",    type="string", example="02101"),
     *                 @OA\Property(property="country",    type="string", example="US")
     *             ),
     *             @OA\Property(
     *                 property="items", type="array", minItems=1,
     *                 @OA\Items(
     *                     required={"product_variant_id"},
     *                     @OA\Property(property="product_variant_id", type="integer", example=42),
     *                     @OA\Property(property="material_id",        type="integer", example=7, description="Optional. Material (chất liệu) id — must belong to the product. Lấy ds qua GET /products (each product has materials[])."),
     *                     @OA\Property(property="accessory_ids",      type="array", @OA\Items(type="integer"), example={18,19}, description="Mảng accessory_prices.id (multi-acc)"),
     *                     @OA\Property(property="accessory_item_id",  type="integer", example=18, description="Legacy single-acc"),
     *                     @OA\Property(property="quantity",           type="integer", default=1, example=2),
     *                     @OA\Property(property="mockup_front",       type="string", example="https://drive.google.com/file/d/FRONT/view"),
     *                     @OA\Property(property="mockup_back",        type="string", example="https://drive.google.com/file/d/BACK/view"),
     *                     @OA\Property(property="order_type",         type="integer", enum={0,1}, default=0, description="0=Greeting Card, 1=Pass Sleeve"),
     *                     @OA\Property(
     *                         property="metas", type="array",
     *                         description="Artwork URLs theo từng mặt in. Xem bảng đầy đủ ở description chính. Mỗi entry: { key, value }. Mỗi mặt thêm (ngoài front) cộng thêm 2nd_fee vào item price.",
     *                         @OA\Items(
     *                             required={"key","value"},
     *                             @OA\Property(
     *                                 property="key", type="string",
     *                                 enum={"front","back","left","right","neck","special"},
     *                                 example="front",
     *                                 description="front=mặt trước (bắt buộc cho hầu hết) · back=mặt sau (+2nd_fee) · left=tay trái · right=tay phải · neck=cổ áo · special=mặt đặc biệt"
     *                             ),
     *                             @OA\Property(
     *                                 property="value", type="string",
     *                                 example="https://drive.google.com/file/d/ABC/view",
     *                                 description="URL ảnh design (Drive / B2 / direct image). Để rỗng để skip (backend tự filter)."
     *                             )
     *                         )
     *                     )
     *                 )
     *             ),
     *             @OA\Property(property="ship_by_stamp", type="boolean", default=false, description="Đơn ship tem — không có tracking, qty ≤ 4. Khi true, address bắt buộc + shipping_label bỏ qua."),
     *             @OA\Property(property="force",  type="boolean", default=false, description="Bỏ qua check 409 duplicate ref_id"),
     *             @OA\Property(property="rename", type="boolean", default=false, description="Auto thêm suffix `_r_1` nếu trùng")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Order created"),
     *     @OA\Response(response=409, description="Duplicate ref_id"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request)
    {
        $byStamp = $request->boolean('ship_by_stamp');
        // Stamp orders always use an address (no carrier label), so a label
        // and stamp are mutually exclusive.
        $hasLabel = !$byStamp && !empty($request->shipping_label);

        $request->validate([
            'ref_id' => 'required|string',
            'note' => 'nullable|string|max:5000',
            'ship_by_stamp' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.material_id' => 'nullable|exists:materials,id',
            // Legacy single accessory (kept for backward compat — callers may
            // still send it). Multi-accessory uses accessory_ids[] below.
            'items.*.accessory_item_id' => 'nullable|exists:accessory_prices,id',
            'items.*.accessory_ids' => 'nullable|array',
            'items.*.accessory_ids.*' => 'integer|exists:accessory_prices,id',
            'items.*.mockup_front' => 'nullable|string',
            'items.*.mockup_back' => 'nullable|string',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.order_type' => 'nullable|integer|in:0,1',
            'items.*.metas' => 'nullable|array',
            'items.*.metas.*.key' => 'required_with:items.*.metas|string',
            'items.*.metas.*.value' => 'nullable|string',
            'shipping_label' => 'nullable|string',
            'address' => $hasLabel ? 'nullable|array' : 'required|array',
            'address.first_name' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.last_name' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.address_1' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.address_2' => 'nullable|string',
            'address.city' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.state' => 'nullable|string',
            'address.zipcode' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.country' => $hasLabel ? 'nullable|string' : 'required|string',
        ]);

        // Stamp orders are capped at the configured max — above that the seller
        // must use a normal label. Sum quantities up front so we reject before
        // creating anything.
        if ($byStamp) {
            $stampMax = Order::stampConfig()['max_items'];
            $stampQty = collect($request->items)->sum(fn ($i) => max(1, (int) ($i['quantity'] ?? 1)));
            if ($stampQty > $stampMax) {
                return response()->json([
                    'message' => 'Ship bằng stamp tối đa ' . $stampMax . ' items. Đơn này ' . $stampQty . ' items — vui lòng dùng label thường.',
                ], 422);
            }
        }

        // Image-URL validation (blocking): a manual create must not produce an
        // order with unreachable / non-image URLs. This moved server-side from
        // the client (was localStorage). Only this create form is gated —
        // external API / bulk paths have their own methods.
        $imgService = app(ImageValidationService::class);
        $imgResult = $imgService->validatePayload($request->items);
        if (!$imgResult['ok']) {
            return response()->json([
                'message' => 'Invalid image URLs',
                'failures' => array_map(fn ($f) => [
                    'item_no' => $f['itemNo'],
                    'field'   => $f['field'],
                    'label'   => $f['label'],
                    'url'     => $f['url'],
                    'reason'  => $f['reason'],
                ], $imgResult['failures']),
            ], 422);
        }

        $userId = $request->user()->id;
        $rawRefId = $request->ref_id ? trim($request->ref_id) : null;
        $force = $request->boolean('force');
        $rename = $request->boolean('rename');

        if ($rawRefId && !$force) {
            $exists = Order::where('user_id', $userId)->where('ref_id', $rawRefId)->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'Duplicate ref_id',
                    'duplicates' => [(string) $rawRefId],
                ], 409);
            }
        }

        $assignedInBatch = [];
        $finalRefId = $rawRefId;
        if ($rawRefId && $force) {
            $finalRefId = $rename
                ? $this->generateUniqueRefId($userId, $rawRefId, $assignedInBatch)
                : $rawRefId; // user explicitly chose to keep duplicate
        }

        return DB::transaction(function () use ($request, $hasLabel, $byStamp, $finalRefId, $imgService, $imgResult) {
            $firstVariantId = (int) ($request->items[0]['product_variant_id'] ?? 0) ?: null;
            $order = Order::create([
                'user_id' => $request->user()->id,
                'system_id' => $this->generateSystemId(), // temporary, updated after we have order.id
                'ref_id' => $finalRefId,
                'note' => $request->input('note'),
                'status' => Order::STATUS_NEW_ORDER,
                'ship_type' => $byStamp ? Order::SHIP_STAMP : ($hasLabel ? Order::SHIP_TIKTOK : Order::SHIP_SELLER),
                'shipping_label' => $hasLabel ? $request->shipping_label : null,
                'shipping_cost' => 0,
            ]);
            $order->update(['system_id' => $this->buildSystemIdForOrder($order->id, $firstVariantId)]);

            $userTierId = $request->user()->tier_id;
            $printCost = 0;
            $totalQty = 0;
            $refVariantId = null;
            foreach ($request->items as $itemData) {
                $price = $this->computeItemPrice($itemData['metas'] ?? null, $itemData['product_variant_id'], $userTierId);
                $quantity = max(1, (int)($itemData['quantity'] ?? 1));

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $itemData['product_variant_id'],
                    'material_id' => $this->resolveMaterialId($itemData['material_id'] ?? null, $itemData['product_variant_id']),
                    'accessory_item_id' => null, // set by syncItemAccessories below
                    'mockup_front' => $itemData['mockup_front'] ?? null,
                    'mockup_back' => $itemData['mockup_back'] ?? null,
                    'order_type' => $itemData['order_type'] ?? 0,
                    'order_type_label' => $this->productOrderType((int) $itemData['product_variant_id']),
                    'price' => $price,
                    'quantity' => $quantity,
                ]);
                $accessoryIds = $this->collectAccessoryIds($itemData);
                $accessoryUnitPrice = $this->syncItemAccessories($item, $accessoryIds, $userTierId);
                $printCost += ($price + $accessoryUnitPrice) * $quantity;
                $totalQty += $quantity;
                if ($refVariantId === null) $refVariantId = $itemData['product_variant_id'];

                if (!empty($itemData['metas'])) {
                    foreach ($itemData['metas'] as $meta) {
                        OrderItemMeta::create([
                            'order_item_id' => $item->id,
                            'key' => $meta['key'],
                            'value' => $meta['value'] ?? null,
                        ]);
                    }
                }
            }

            if (!$hasLabel && $request->address) {
                Address::create(array_merge($request->address, ['order_id' => $order->id]));
            }

            $shippingCost = $byStamp
                ? Order::stampShippingCost($totalQty)
                : $this->computeOrderShipping($refVariantId, $userTierId, $hasLabel, $totalQty);

            $order->update([
                'print_cost' => $printCost,
                'shipping_cost' => $shippingCost,
                'total_cost' => $printCost + $shippingCost,
            ]);

            // Images were just validated above (all passed) — persist the
            // pass without re-fetching so the Order page shows checked=true.
            $imgService->recordPass($order, $imgResult['image_count']);

            // Best-effort Telegram notification — never blocks order creation.
            try {
                app(\App\Services\TelegramService::class)->notifyNewOrder($order, $request->user());
            } catch (\Throwable $e) {
                \Log::warning('[telegram] notifyNewOrder: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Order created',
                'order' => $order->fresh()->load(['items.metas', 'items.productVariant', 'items.material', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'items.accessoryPrices.accessory', 'items.accessoryPrices.tier', 'address']),
            ], 201);
        });
    }

    /**
     * GET /api/orders/{order}/ship-cost-preview?ship_type=stamp|seller_ship|tiktok_ship
     *
     * Staff-only. Returns the shipping_cost + total_cost the order WOULD get if
     * its ship_type were changed — without saving. Mirrors update()'s recompute
     * so the edit form can show the new price live before the user hits Save.
     */
    public function shipCostPreview(Request $request, Order $order)
    {
        $user = $request->user();
        if (!($user && ($user->hasRole('admin') || $user->hasRole('support')))) {
            return response()->json(['message' => 'Only staff can preview ship cost'], 403);
        }
        $request->validate([
            'ship_type' => 'required|in:tiktok_ship,seller_ship,stamp',
        ]);

        $order->loadMissing('items');
        $totalQty       = $order->items->sum(fn ($i) => max(1, (int) ($i->quantity ?? 1)));
        $firstVariantId = (int) ($order->items->first()?->product_variant_id ?? 0);
        $tierId         = $order->user?->tier_id;
        $shipType       = $request->ship_type;

        if ($shipType === Order::SHIP_STAMP) {
            $shipping = Order::stampShippingCost($totalQty);
        } elseif ($shipType === Order::SHIP_SELLER) {
            $shipping = $firstVariantId
                ? $this->computeOrderShipping($firstVariantId, $tierId, false, $totalQty)
                : 0;
        } else { // tiktok_ship: label_fee when a label is present, else 0.
            $hasLabel = !empty($order->shipping_label);
            $shipping = ($firstVariantId && $hasLabel)
                ? $this->computeOrderShipping($firstVariantId, $tierId, true, $totalQty)
                : 0;
        }
        $shipping = round((float) $shipping, 2);

        return response()->json([
            'ship_type'     => $shipType,
            'shipping_cost' => $shipping,
            'print_cost'    => round((float) $order->print_cost, 2),
            'total_cost'    => round((float) $order->print_cost + $shipping, 2),
            'clears_label'  => in_array($shipType, [Order::SHIP_STAMP, Order::SHIP_SELLER], true),
        ]);
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'note' => 'nullable|string|max:5000',
            'shipping_label' => 'nullable|string',
            'tracking_id' => 'nullable|string',
            'shipping_cost' => 'nullable|numeric|min:0',
            'print_cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|integer|in:0,1,2,3,4,5,6,7',
            // Proof photo of the stamp stuck on the envelope — uploaded by the
            // fulfiller and shown to the seller (stamp has no tracking).
            'proof_image' => 'nullable|url|max:1024',
            'ship_type'   => 'nullable|in:' . Order::SHIP_TIKTOK . ',' . Order::SHIP_SELLER . ',' . Order::SHIP_STAMP,
            // Shipping address — required for stamp orders. Staff-only edit.
            'address'              => 'sometimes|array',
            'address.first_name'   => 'nullable|string|max:120',
            'address.last_name'    => 'nullable|string|max:120',
            'address.address_1'    => 'nullable|string|max:255',
            'address.address_2'    => 'nullable|string|max:255',
            'address.city'         => 'nullable|string|max:120',
            'address.state'        => 'nullable|string|max:120',
            'address.zipcode'      => 'nullable|string|max:32',
            'address.country'      => 'nullable|string|max:120',
            'address.phone'        => 'nullable|string|max:64',
            'address.email'        => 'nullable|string|max:191',
        ]);

        $user = $request->user();
        // Only block when a seller actually CHANGES the status to a disallowed
        // value. Edit forms resend the current status alongside other fields
        // (e.g. note), which must not trip this guard.
        if ($user && $user->hasRole('seller') && $request->filled('status')
            && (int) $request->status !== (int) $order->status
            && !in_array((int) $request->status, [Order::STATUS_ONHOLD, Order::STATUS_CANCELLED], true)) {
            return response()->json(['message' => 'Sellers can only set status to onhold or cancelled'], 403);
        }
        // A shipped order is final for sellers — they can't cancel/un-ship it.
        if ($user && $user->hasRole('seller') && $request->filled('status')
            && (int) $order->status === Order::STATUS_SHIPPED
            && (int) $request->status !== Order::STATUS_SHIPPED) {
            return response()->json(['message' => 'Đơn đã shipped — seller không thể huỷ/đổi trạng thái. Liên hệ admin.'], 403);
        }

        $data = $request->only('shipping_label', 'tracking_id', 'shipping_cost', 'print_cost', 'status', 'proof_image');
        if ($request->has('note')) {
            $data['note'] = $request->input('note');
        }

        // Ship-type change — only staff. Each transition recomputes the
        // shipping cost from the items + the variant price table so the
        // stored value reflects the new mode's pricing rule:
        //   stamp        → stampShippingCost() + clear label/tracking
        //   seller_ship  → base + addition × (qty-1) + clear label/tracking
        //   tiktok_ship  → label_fee (when a label is present)
        // The caller can override by passing an explicit shipping_cost.
        if ($request->filled('ship_type') && $request->ship_type !== $order->ship_type) {
            if (!($user && ($user->hasRole('admin') || $user->hasRole('support')))) {
                return response()->json(['message' => 'Only staff can change ship_type'], 403);
            }
            $data['ship_type'] = $request->ship_type;

            $order->loadMissing('items');
            $totalQty = $order->items->sum(fn ($i) => max(1, (int) ($i->quantity ?? 1)));
            $firstVariantId = (int) ($order->items->first()?->product_variant_id ?? 0);
            $tierId = $order->user?->tier_id;

            if ($request->ship_type === Order::SHIP_STAMP) {
                $data['shipping_cost']  = Order::stampShippingCost($totalQty);
                $data['shipping_label'] = null;
                $data['tracking_id']    = null;
            } elseif ($request->ship_type === Order::SHIP_SELLER) {
                // seller_ship: base + addition × (qty-1). Clear label/tracking
                // since the seller will buy + attach their own.
                $data['shipping_cost']  = $firstVariantId
                    ? $this->computeOrderShipping($firstVariantId, $tierId, false, $totalQty)
                    : 0;
                $data['shipping_label'] = null;
                $data['tracking_id']    = null;
            } elseif (!$request->has('shipping_cost')) {
                // tiktok_ship: charge label_fee if a label is set, else 0.
                $hasLabel = !empty($request->shipping_label ?? $order->shipping_label);
                $data['shipping_cost'] = ($firstVariantId && $hasLabel)
                    ? $this->computeOrderShipping($firstVariantId, $tierId, true, $totalQty)
                    : 0;
            }
        }

        // Recalculate total_cost if any cost-affecting field changed
        if (array_key_exists('shipping_cost', $data) || array_key_exists('print_cost', $data)) {
            $printCost    = $data['print_cost']    ?? $order->print_cost;
            $shippingCost = $data['shipping_cost'] ?? $order->shipping_cost;
            $data['total_cost'] = round((float) $printCost + (float) $shippingCost, 2);
        }

        if (isset($data['status']) && $data['status'] == Order::STATUS_SHIPPED) {
            $data['completed_time'] = now();
        }

        $order->update($data);

        // Address upsert — staff only. Useful for stamp orders that get
        // their shipping address edited after creation. Owner column ties
        // it to this order, so we update-or-create.
        if ($request->has('address') && is_array($request->address)
            && $user && ($user->hasRole('admin') || $user->hasRole('support'))) {
            $addrData = collect($request->address)->only([
                'first_name', 'last_name', 'address_1', 'address_2',
                'city', 'state', 'zipcode', 'country', 'phone', 'email',
            ])->toArray();
            if (array_filter($addrData, fn ($v) => $v !== null && $v !== '')) {
                \App\Models\Address::updateOrCreate(
                    ['order_id' => $order->id],
                    $addrData
                );
            }
        }

        if (isset($data['status'])) {
            $data['status'] == Order::STATUS_SHIPPED
                ? $order->fresh()->decrementStockForShipment()
                : $order->fresh()->restockForUnship();   // rời shipped → trả lại kho
        }

        return response()->json([
            'message' => 'Order updated',
            'order' => $order->fresh()->load(['items.metas', 'items.productVariant', 'items.material', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'items.accessoryPrices.accessory', 'items.accessoryPrices.tier', 'address']),
        ]);
    }

    public function destroy(Request $request, Order $order)
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            return response()->json(['message' => 'Only admin can delete orders'], 403);
        }

        $order->delete();
        return response()->json(['message' => 'Order deleted']);
    }

    public function bulkReProduction(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            return response()->json(['message' => 'Only admin can bulk re-production'], 403);
        }

        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        return DB::transaction(function () use ($request) {
            $orders = Order::whereIn('id', $request->order_ids)->get();
            $itemIds = OrderItem::whereIn('order_id', $orders->pluck('id'))->pluck('id');

            $metasReset = OrderItemMeta::whereIn('order_item_id', $itemIds)
                ->where('production', true)
                ->update(['production' => false]);

            Order::whereIn('id', $orders->pluck('id'))->update(['production' => false]);

            return response()->json([
                'message' => "Re-production: {$orders->count()} order(s), {$metasReset} meta(s) reset",
                'count' => $orders->count(),
                'metas_reset' => $metasReset,
            ]);
        });
    }

    public function bulkMergeDuplicates(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            return response()->json(['message' => 'Only admin can merge orders'], 403);
        }

        $request->validate([
            'order_ids' => 'required|array|min:2',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $orders = Order::with('items.accessoryPrices')
            ->whereIn('id', $request->order_ids)
            ->whereNotNull('ref_id')
            ->get();

        if ($orders->count() < 2) {
            return response()->json(['message' => 'Need at least 2 orders with ref_id to merge'], 400);
        }

        // Group selected orders by (user_id, ref_id). Only groups of size >= 2 merge.
        $groups = $orders->groupBy(fn($o) => $o->user_id . '|' . $o->ref_id)
            ->filter(fn($g) => $g->count() >= 2);

        if ($groups->isEmpty()) {
            return response()->json(['message' => 'No duplicate ref_id groups in the selection'], 400);
        }

        $merged = 0;
        $deleted = 0;
        $details = [];

        DB::transaction(function () use ($groups, &$merged, &$deleted, &$details) {
            foreach ($groups as $key => $group) {
                // Winner = newest (highest id). Losers = the rest.
                $sorted  = $group->sortByDesc('id')->values();
                $winner  = $sorted->first();
                $losers  = $sorted->slice(1);
                $loserIds = $losers->pluck('id')->all();

                // 1. Move OrderItems from losers to winner.
                OrderItem::whereIn('order_id', $loserIds)->update(['order_id' => $winner->id]);

                // 2. Re-link historical wallet_transactions so paid/refund history follows the winner.
                \App\Models\WalletTransaction::whereIn('order_id', $loserIds)->update(['order_id' => $winner->id]);

                // 3. Sum paid_cost across the group → winner inherits total payment.
                $totalPaid = $group->sum('paid_cost');

                // 4. Drop loser address rows + the loser orders themselves.
                Address::whereIn('order_id', $loserIds)->delete();
                $deleted += Order::whereIn('id', $loserIds)->delete();

                // 5. Recompute winner totals from the merged items.
                $winner->load('items.accessoryPrices', 'user');
                $totalQty = 0;
                $totalPrint = 0;
                foreach ($winner->items as $it) {
                    $qty   = max(1, (int) ($it->quantity ?? 1));
                    $acc   = (float) $it->accessoryPrices->sum('price');
                    $totalPrint += ((float) $it->price + $acc) * $qty;
                    $totalQty   += $qty;
                }
                $hasLabel = !empty($winner->shipping_label);
                $firstVariantId = (int) ($winner->items->first()?->product_variant_id ?? 0);
                $shippingCost = $firstVariantId
                    ? $this->computeOrderShipping($firstVariantId, $winner->user?->tier_id, $hasLabel, $totalQty)
                    : (float) $winner->shipping_cost;

                $winner->update([
                    'print_cost'    => $totalPrint,
                    'shipping_cost' => $shippingCost,
                    'total_cost'    => $totalPrint + $shippingCost,
                    'paid_cost'     => $totalPaid,
                ]);

                $merged++;
                $details[] = [
                    'ref_id'         => $winner->ref_id,
                    'winner_id'      => $winner->id,
                    'winner_system'  => $winner->system_id,
                    'merged_count'   => $losers->count(),
                    'loser_ids'      => $loserIds,
                    'item_count'     => $winner->items->count(),
                    'total_cost'     => $totalPrint + $shippingCost,
                ];
            }
        });

        // Duplicate snapshot is now stale.
        $this->refreshDuplicateRefIds();

        return response()->json([
            'message' => "Merged {$merged} group(s); deleted {$deleted} duplicate order(s)",
            'merged_groups' => $merged,
            'deleted_orders' => $deleted,
            'details' => $details,
        ]);
    }

    public function bulkAssign(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            return response()->json(['message' => 'Only admin can bulk assign orders'], 403);
        }

        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $count = Order::whereIn('id', $request->order_ids)
            ->update(['user_id' => $request->user_id]);

        $target = \App\Models\User::find($request->user_id);

        $this->refreshDuplicateRefIds();

        return response()->json([
            'message' => "Assigned {$count} order(s) to {$target->name}",
            'count' => $count,
            'user_id' => $request->user_id,
        ]);
    }

    public function bulkSetProduction(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            return response()->json(['message' => 'Only admin can bulk set production'], 403);
        }

        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        return DB::transaction(function () use ($request) {
            $orders = Order::whereIn('id', $request->order_ids)->get();
            $itemIds = OrderItem::whereIn('order_id', $orders->pluck('id'))->pluck('id');

            $metasMarked = OrderItemMeta::whereIn('order_item_id', $itemIds)
                ->where('production', false)
                ->update(['production' => true]);

            Order::whereIn('id', $orders->pluck('id'))->update(['production' => true]);

            return response()->json([
                'message' => "Marked production: {$orders->count()} order(s), {$metasMarked} meta(s) updated",
                'count' => $orders->count(),
                'metas_marked' => $metasMarked,
            ]);
        });
    }

    public function bulkDestroy(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('admin')) {
            return response()->json(['message' => 'Only admin can delete orders'], 403);
        }

        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $deleted = Order::whereIn('id', $request->order_ids)->delete();

        $this->refreshDuplicateRefIds();

        return response()->json([
            'message' => "Deleted {$deleted} order(s)",
            'count' => $deleted,
        ]);
    }

    /**
     * Recompute the duplicate_ref_ids snapshot inline. Cheap (single grouped
     * INSERT) — call after bulk write paths so the next /orders index sees
     * fresh duplicate flags without waiting for the scheduled refresh.
     */
    private function refreshDuplicateRefIds(): void
    {
        \Illuminate\Support\Facades\DB::table('duplicate_ref_ids')->delete();
        \Illuminate\Support\Facades\DB::statement("
            INSERT INTO duplicate_ref_ids (user_id, ref_id, count, computed_at)
            SELECT user_id, ref_id, COUNT(*) AS cnt, NOW()
            FROM orders
            WHERE ref_id IS NOT NULL AND ref_id != ''
            GROUP BY user_id, ref_id
            HAVING COUNT(*) > 1
        ");
    }

    public function pendingTracking(Request $request)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role?->slug, ['admin', 'support'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $limit = max(1, min(500, (int) $request->get('limit', 200)));

        $orders = Order::pendingTracking()
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get(['id', 'system_id', 'shipping_label']);

        return response()->json(['data' => $orders, 'count' => $orders->count()]);
    }

    public function saveTracking(Request $request, Order $order)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role?->slug, ['admin', 'support'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'tracking_id' => 'required|string|max:255',
        ]);

        $order->update(['tracking_id' => trim($request->tracking_id)]);

        return response()->json([
            'message' => 'Tracking saved',
            'tracking_id' => $order->fresh()->tracking_id,
            'order_id' => $order->id,
        ]);
    }

    public function fetchTrackingOne(Request $request, Order $order)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role?->slug, ['admin', 'support'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($order->tracking_id) {
            return response()->json(['message' => 'Already has tracking', 'tracking_id' => $order->tracking_id]);
        }
        if (empty($order->shipping_label)) {
            return response()->json(['message' => 'No shipping_label'], 400);
        }

        try {
            // Shared carrier call (Drive-URL rewrite + multi-key parse live in the service).
            $result = app(\App\Services\CarrierTrackingService::class)->fetch($order->shipping_label);
            $tracking = $result['tracking_id'] ?? null;

            if (!$tracking) {
                // Use 422 (not 502) so web servers don't replace our JSON body
                // with their own HTML error page — frontend needs the upstream
                // message to know whether to retry or skip the order.
                return response()->json([
                    'message' => 'No tracking in carrier response',
                    'body' => $result['raw'] ?? null,
                ], 422);
            }

            $order->update(['tracking_id' => $tracking]);

            return response()->json([
                'message' => 'Tracking saved',
                'tracking_id' => $tracking,
                'order_id' => $order->id,
                'system_id' => $order->system_id,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function runDuplicateRefresh(Request $request)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role?->slug, ['admin', 'support'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $started = microtime(true);
        $this->refreshDuplicateRefIds();
        $count = \Illuminate\Support\Facades\DB::table('duplicate_ref_ids')->count();
        $ms = (int) ((microtime(true) - $started) * 1000);

        return response()->json([
            'message' => "Refreshed {$count} duplicate ref_id(s) in {$ms}ms",
            'count' => $count,
            'duration_ms' => $ms,
        ]);
    }

    /**
     * Same as reconvert(), but for multiple orders at once. Body: order_ids[].
     * Non-staff users can only target their own orders; orders they don't own
     * are silently skipped and reported in the response.
     */
    public function bulkReconvert(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $user = $request->user();
        if (!$user) abort(401);
        $isStaff = $user->role && in_array($user->role->slug, ['admin', 'support']);

        $query = Order::whereIn('id', $request->order_ids);
        if (!$isStaff) {
            $query->where('user_id', $user->id);
        }
        $orders = $query->get();
        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No accessible orders to reconvert', 'count' => 0, 'deleted' => 0], 403);
        }

        $totalDeleted = 0;
        foreach ($orders as $order) {
            $itemIds = $order->items()->pluck('id');
            $deleted = OrderItemMeta::whereIn('order_item_id', $itemIds)
                ->where('key', 'regexp', '_qr(_[0-9]+)?$')
                ->delete();
            $totalDeleted += $deleted;
            $order->update(['production' => false]);
        }

        return response()->json([
            'message' => "Reconvert queued for {$orders->count()} order(s); removed {$totalDeleted} _qr meta(s).",
            'count' => $orders->count(),
            'deleted' => $totalDeleted,
            'skipped' => count($request->order_ids) - $orders->count(),
        ]);
    }

    /**
     * Wipe all *_qr metas of this order so the converter cron rebuilds them
     * the next time it polls. Also resets the order's production flag so it
     * can be re-ganged. Allowed for the order's owner, admin, or support.
     */
    public function updateItemMetas(Request $request, OrderItem $item)
    {
        $user = $request->user();
        $isStaff = $user?->role && in_array($user->role->slug, ['admin', 'support']);
        $isOwner = $item->order && $item->order->user_id === $user?->id;
        if (!$isStaff && !$isOwner) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $sourceKeys = ['front', 'back', 'left', 'right', 'neck', 'special'];

        $request->validate([
            'metas' => 'required|array|min:1',
            'metas.*.key' => ['required', 'string', \Illuminate\Validation\Rule::in($sourceKeys)],
            'metas.*.value' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $item, $sourceKeys) {
            $changedKeys = [];
            foreach ($request->metas as $m) {
                $key = $m['key'];
                $value = $m['value'] ?? null;

                $existing = OrderItemMeta::where('order_item_id', $item->id)
                    ->where('key', $key)
                    ->first();

                $oldValue = $existing?->value;
                if ($oldValue === $value) continue; // skip no-op

                if ($existing) {
                    if ($value === null || $value === '') {
                        $existing->delete();
                    } else {
                        $existing->update(['value' => $value, 'production' => false]);
                    }
                } elseif ($value !== null && $value !== '') {
                    OrderItemMeta::create([
                        'order_item_id' => $item->id,
                        'key' => $key,
                        'value' => $value,
                        'production' => false,
                    ]);
                }
                $changedKeys[] = $key;
            }

            if (!empty($changedKeys)) {
                // Drop the corresponding _qr metas so the converter regenerates
                // them from the new source URLs.
                $qrPattern = '_qr(_[0-9]+)?$';
                $deleted = OrderItemMeta::where('order_item_id', $item->id)
                    ->where(function ($q) use ($changedKeys, $qrPattern) {
                        foreach ($changedKeys as $k) {
                            $q->orWhere('key', 'regexp', '^' . $k . $qrPattern);
                        }
                    })
                    ->delete();

                // Reset production flags so the order re-enters the gangsheet pipeline.
                $item->order->update(['production' => false]);
                OrderItemMeta::where('order_item_id', $item->id)
                    ->where('production', true)
                    ->update(['production' => false]);

                return response()->json([
                    'message' => "Updated " . count($changedKeys) . " meta(s); removed {$deleted} _qr meta(s) for regeneration",
                    'changed_keys' => $changedKeys,
                    'deleted_qr' => $deleted,
                ]);
            }

            return response()->json(['message' => 'No changes', 'changed_keys' => []]);
        });
    }

    /**
     * Update an order item's mockup_front / mockup_back image URLs. Used by the
     * OrderDetail inline mockup editor so users can re-upload / replace mockups
     * after an order is created.
     *
     * Authorization mirrors updateItemMetas: admin/support, or the order's owner.
     * Mockups are customer-facing preview images — they are NOT gangsheet design
     * sources — so changing them does not reset production or _qr metas.
     */
    public function updateItemMockups(Request $request, OrderItem $item)
    {
        $user = $request->user();
        $isStaff = $user?->role && in_array($user->role->slug, ['admin', 'support']);
        $isOwner = $item->order && $item->order->user_id === $user?->id;
        if (!$isStaff && !$isOwner) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'mockup_front' => 'nullable|string',
            'mockup_back'  => 'nullable|string',
        ]);

        $item->update([
            'mockup_front' => $data['mockup_front'] ?? null,
            'mockup_back'  => $data['mockup_back'] ?? null,
        ]);

        return response()->json([
            'message' => 'Mockups updated',
            'item' => $item->only(['id', 'mockup_front', 'mockup_back']),
        ]);
    }

    /**
     * Replace the accessories attached to an order item, then recompute the
     * parent order's print_cost / total_cost. Used by the OrderDetail "Edit
     * accessories" inline editor.
     *
     * Authorization mirrors updateItemMetas: admin/support, or the order's
     * owner. Shipped/cancelled orders are locked since pricing/stock has
     * already been settled.
     *
     * Prices are looked up at the ORDER OWNER'S tier — not the caller's —
     * because the seller's tier set the unit price when the order was
     * created and we shouldn't switch tiers mid-flight just because an
     * admin is editing.
     */
    public function updateItemAccessories(Request $request, OrderItem $item)
    {
        $caller = $request->user();
        $item->loadMissing('order.user');
        $order = $item->order;
        if (!$order) return response()->json(['message' => 'Item has no order'], 404);

        $isStaff = $caller?->role && in_array($caller->role->slug, ['admin', 'support']);
        $isOwner = $order->user_id === $caller?->id;
        if (!$isStaff && !$isOwner) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (in_array((int) $order->status, [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED], true)) {
            return response()->json(['message' => 'Order is shipped or cancelled — accessories are locked'], 422);
        }

        $request->validate([
            'accessory_ids' => 'present|array',
            'accessory_ids.*' => 'integer|exists:accessory_prices,id',
        ]);

        $ownerTierId = $order->user?->tier_id;
        if ($ownerTierId === null) {
            return response()->json(['message' => "Order owner has no tier — can't price accessories"], 422);
        }

        return DB::transaction(function () use ($request, $item, $order, $ownerTierId) {
            $accIds = array_values(array_unique(array_map('intval', $request->accessory_ids)));
            $this->syncItemAccessories($item, $accIds, $ownerTierId);

            // Recompute order totals from every item now in the order.
            $order->load('items.accessoryPrices', 'user');
            $totalQty = 0;
            $totalPrint = 0;
            foreach ($order->items as $it) {
                $itQty = max(1, (int) ($it->quantity ?? 1));
                $itAcc = (float) $it->accessoryPrices->sum('price');
                $totalPrint += ((float) $it->price + $itAcc) * $itQty;
                $totalQty += $itQty;
            }
            // Shipping is set from the order's first variant — same convention
            // as createOrder/importCsv. Keep current shipping_cost if no
            // variant exists (shouldn't happen but safe).
            $hasLabel = !empty($order->shipping_label);
            $firstVariantId = (int) ($order->items->first()?->product_variant_id ?? 0);
            $shippingCost = $firstVariantId
                ? $this->computeOrderShipping($firstVariantId, $order->user?->tier_id, $hasLabel, $totalQty)
                : (float) $order->shipping_cost;
            $order->update([
                'print_cost'    => $totalPrint,
                'shipping_cost' => $shippingCost,
                'total_cost'    => $totalPrint + $shippingCost,
            ]);

            return response()->json([
                'message' => 'Accessories updated; order totals recomputed',
                'item_id' => $item->id,
                'accessory_ids' => $item->accessoryPrices()->pluck('accessory_prices.id'),
                'order' => $order->fresh()->load([
                    'items.metas', 'items.productVariant', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier',
                    'items.accessoryPrices.accessory', 'items.accessoryPrices.tier', 'address',
                ]),
            ]);
        });
    }

    /**
     * PUT /api/order-items/{item}/order-type — set the item's order_type label
     * (order_items.order_type_label). Staff/owner, blocked only when cancelled.
     * Body: { order_type: string|null }. Empty clears it (falls back to the
     * product's order_type on display).
     */
    public function updateItemOrderType(Request $request, OrderItem $item)
    {
        $caller = $request->user();
        $item->loadMissing('order');
        $order = $item->order;
        if (!$order) return response()->json(['message' => 'Item has no order'], 404);

        $isStaff = $caller?->role && in_array($caller->role->slug, ['admin', 'support']);
        if (!$isStaff && $order->user_id !== $caller?->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ((int) $order->status === Order::STATUS_CANCELLED) {
            return response()->json(['message' => 'Order is cancelled — type is locked'], 422);
        }

        $data = $request->validate([
            'order_type' => 'present|nullable|string|max:64',
        ]);
        $label = $data['order_type'] !== null ? trim($data['order_type']) : null;
        $item->update(['order_type_label' => ($label === '' ? null : $label)]);

        return response()->json([
            'message'          => 'Item type updated',
            'item_id'          => $item->id,
            'order_type_label' => $item->order_type_label,
        ]);
    }

    /**
     * GET /api/order-items/{item}/variant-preview?product_variant_id=X
     *
     * Staff/owner. Returns the item price + order totals the order WOULD get if
     * this item's variant were swapped — without saving. Mirrors
     * updateItemVariant's recompute so the UI can review the new price first.
     */
    public function variantPreview(Request $request, OrderItem $item)
    {
        $caller = $request->user();
        $item->loadMissing('order.user', 'metas');
        $order = $item->order;
        if (!$order) return response()->json(['message' => 'Item has no order'], 404);

        $isStaff = $caller?->role && in_array($caller->role->slug, ['admin', 'support']);
        if (!$isStaff && $order->user_id !== $caller?->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $request->validate(['product_variant_id' => 'required|integer|exists:product_variants,id']);

        $tierId = $order->user?->tier_id;
        if ($tierId === null) {
            return response()->json(['message' => "Order owner has no tier — can't price the variant"], 422);
        }

        $newVariantId = (int) $request->product_variant_id;
        $newItemPrice = $this->computeItemPrice($item->metas, $newVariantId, $tierId);

        // Recompute totals with this item priced at the new variant.
        $order->load('items.accessoryPrices');
        $totalQty = 0;
        $totalPrint = 0;
        foreach ($order->items as $it) {
            $itQty   = max(1, (int) ($it->quantity ?? 1));
            $itAcc   = (float) $it->accessoryPrices->sum('price');
            $itPrice = $it->id === $item->id ? $newItemPrice : (float) $it->price;
            $totalPrint += ($itPrice + $itAcc) * $itQty;
            $totalQty   += $itQty;
        }
        $hasLabel = !empty($order->shipping_label);
        // First variant drives shipping; if THIS item is first, use the new one.
        $firstItem = $order->items->first();
        $firstVariantId = ($firstItem && $firstItem->id === $item->id)
            ? $newVariantId
            : (int) ($firstItem?->product_variant_id ?? 0);
        $shipping = $firstVariantId
            ? $this->computeOrderShipping($firstVariantId, $tierId, $hasLabel, $totalQty)
            : (float) $order->shipping_cost;

        return response()->json([
            'item_price'    => round((float) $newItemPrice, 2),
            'old_item_price'=> round((float) $item->price, 2),
            'print_cost'    => round((float) $totalPrint, 2),
            'shipping_cost' => round((float) $shipping, 2),
            'total_cost'    => round((float) $totalPrint + $shipping, 2),
            'old_total_cost'=> round((float) $order->total_cost, 2),
        ]);
    }

    /**
     * PUT /api/order-items/{item}/variant — swap the item's product variant for
     * a different one and re-price. Staff or the order owner; blocked once the
     * order is shipped/cancelled. Re-prices the item from the new variant
     * (base_cost + 2nd_fee per its design faces) and recomputes order totals —
     * same recompute path as updateItemAccessories.
     *
     * Body: { product_variant_id: int }
     */
    public function updateItemVariant(Request $request, OrderItem $item)
    {
        $caller = $request->user();
        $item->loadMissing('order.user', 'metas');
        $order = $item->order;
        if (!$order) return response()->json(['message' => 'Item has no order'], 404);

        $isStaff = $caller?->role && in_array($caller->role->slug, ['admin', 'support']);
        $isOwner = $order->user_id === $caller?->id;
        if (!$isStaff && !$isOwner) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ((int) $order->status === Order::STATUS_CANCELLED) {
            return response()->json(['message' => 'Order is cancelled — variant is locked'], 422);
        }

        $request->validate([
            'product_variant_id' => 'required|integer|exists:product_variants,id',
        ]);

        $ownerTierId = $order->user?->tier_id;
        if ($ownerTierId === null) {
            return response()->json(['message' => "Order owner has no tier — can't price the variant"], 422);
        }

        return DB::transaction(function () use ($request, $item, $order, $ownerTierId) {
            $newVariantId = (int) $request->product_variant_id;
            // Re-price the item from the new variant using its existing design
            // faces (base_cost + 2nd_fee × (faces − 1)).
            $newPrice = $this->computeItemPrice($item->metas, $newVariantId, $ownerTierId);
            $item->update([
                'product_variant_id' => $newVariantId,
                'order_type_label'   => $this->productOrderType($newVariantId),
                'price'              => $newPrice,
            ]);

            // Recompute order totals from every item (mirrors updateItemAccessories).
            $order->load('items.accessoryPrices', 'user');
            $totalQty = 0;
            $totalPrint = 0;
            foreach ($order->items as $it) {
                $itQty = max(1, (int) ($it->quantity ?? 1));
                $itAcc = (float) $it->accessoryPrices->sum('price');
                $totalPrint += ((float) $it->price + $itAcc) * $itQty;
                $totalQty += $itQty;
            }
            $hasLabel = !empty($order->shipping_label);
            $firstVariantId = (int) ($order->items->first()?->product_variant_id ?? 0);
            $shippingCost = $firstVariantId
                ? $this->computeOrderShipping($firstVariantId, $order->user?->tier_id, $hasLabel, $totalQty)
                : (float) $order->shipping_cost;
            $order->update([
                'print_cost'    => $totalPrint,
                'shipping_cost' => $shippingCost,
                'total_cost'    => $totalPrint + $shippingCost,
            ]);

            return response()->json([
                'message' => 'Variant updated; order totals recomputed',
                'item_id' => $item->id,
                'order'   => $order->fresh()->load([
                    'items.metas', 'items.productVariant.product', 'items.material',
                    'items.accessoryPrice.accessory', 'items.accessoryPrice.tier',
                    'items.accessoryPrices.accessory', 'items.accessoryPrices.tier', 'address',
                ]),
            ]);
        });
    }

    /**
     * Bulk-update the envelope/accessory of existing orders from a CSV-style
     * payload. Each row carries a ref_id and an accessory_code (e.g. "W"/"R"/"B").
     * Rows are applied to the matching order's items in id ASC order, so a
     * ref_id with N rows updates the first N items.
     *
     * Body: { rows: [{ ref_id, accessory_code }] }
     * Admin / support only — sellers update their own orders through the
     * normal order edit screen.
     */
    public function importEnvelopes(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.ref_id' => 'required|string',
            'rows.*.accessory_code' => 'nullable|string',
        ]);

        $user = $request->user();
        $isStaff = $user && $user->role && in_array($user->role->slug, ['admin', 'support']);
        if (!$isStaff) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Group rows by ref_id, preserving original sequence — we use the
        // position within the group as the item index when applying updates.
        $grouped = [];
        foreach ($request->rows as $row) {
            $rid = trim($row['ref_id']);
            $code = strtoupper(trim((string) ($row['accessory_code'] ?? '')));
            if ($rid === '') continue;
            $grouped[$rid][] = $code;
        }

        $refIds = array_keys($grouped);
        // Load orders + items + product (for accessory lookup) + user (for tier).
        $orders = Order::with(['items.productVariant.product', 'user:id,tier_id'])
            ->whereIn('ref_id', $refIds)
            ->orderBy('id', 'desc')
            ->get()
            ->groupBy('ref_id');

        $results = [];
        $updated = 0; $skipped = 0; $errors = 0;

        foreach ($grouped as $refId => $codes) {
            $orderList = $orders->get($refId);
            if (!$orderList || $orderList->isEmpty()) {
                foreach ($codes as $i => $code) {
                    $results[] = ['ref_id' => $refId, 'index' => $i, 'status' => 'no_order', 'message' => "No order with ref_id {$refId}"];
                    $errors++;
                }
                continue;
            }
            // Multiple orders share a ref_id when sellers re-upload. Take the
            // most recent — same convention the dup-detector uses elsewhere.
            $order = $orderList->first();
            $items = $order->items->sortBy('id')->values();
            $tierId = $order->user?->tier_id;

            foreach ($codes as $i => $code) {
                $rowResult = ['ref_id' => $refId, 'order_id' => $order->id, 'system_id' => $order->system_id, 'index' => $i];

                if ($code === '') {
                    $rowResult += ['status' => 'skipped', 'message' => 'No accessory_code in row'];
                    $skipped++;
                    $results[] = $rowResult;
                    continue;
                }

                $item = $items->get($i);
                if (!$item) {
                    $rowResult += ['status' => 'no_item', 'message' => "Order has only {$items->count()} item(s); row {$i} skipped"];
                    $errors++;
                    $results[] = $rowResult;
                    continue;
                }

                $productId = $item->productVariant?->product_id;
                if (!$productId || !$tierId) {
                    $rowResult += ['status' => 'no_accessory', 'message' => 'Item missing product or seller tier'];
                    $errors++;
                    $results[] = $rowResult;
                    continue;
                }

                $accessory = AccessoryPrice::whereHas('accessory', fn ($q) => $q->where('product_id', $productId))
                    ->where('tier_id', $tierId)
                    ->where('accessory_code', $code)
                    ->first();
                if (!$accessory) {
                    $rowResult += ['status' => 'no_accessory', 'message' => "No accessory '{$code}' for product {$productId} / tier {$tierId}"];
                    $errors++;
                    $results[] = $rowResult;
                    continue;
                }

                if ($item->accessory_item_id === $accessory->id) {
                    $rowResult += ['status' => 'no_change', 'message' => "Already {$code}"];
                    $skipped++;
                    $results[] = $rowResult;
                    continue;
                }

                // Envelope import = single-accessory replace. Whatever pivot
                // rows existed get wiped and the new code becomes the only
                // accessory + the primary legacy reference.
                $item->update(['accessory_item_id' => $accessory->id]);
                $item->accessoryPrices()->sync([$accessory->id]);
                $rowResult += ['status' => 'ok', 'message' => "Updated item {$item->id} → {$code}", 'item_id' => $item->id, 'accessory_id' => $accessory->id];
                $updated++;
                $results[] = $rowResult;
            }
        }

        return response()->json([
            'summary' => [
                'rows_total' => count($results),
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
                'orders_touched' => count(array_unique(array_filter(array_column($results, 'order_id')))),
            ],
            'results' => $results,
        ]);
    }

    public function reconvert(Request $request, Order $order)
    {
        $user = $request->user();
        if (!$user) abort(401);

        $isStaff = $user->role && in_array($user->role->slug, ['admin', 'support']);
        $isOwner = $order->user_id === $user->id;
        if (!$isStaff && !$isOwner) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $itemIds = $order->items()->pluck('id');
        $deleted = OrderItemMeta::whereIn('order_item_id', $itemIds)
            ->where('key', 'regexp', '_qr(_[0-9]+)?$')
            ->delete();

        $order->update(['production' => false]);

        return response()->json([
            'message' => "Reconvert queued: removed {$deleted} _qr meta(s). The converter cron will rebuild them.",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Clear the convert_label column so the converter cron picks the order
     * back up and re-runs the overlay. Admin/support only — convert_label is
     * an internal artifact and sellers do not see the column.
     */
    public function reconvertLabel(Request $request, Order $order)
    {
        $user = $request->user();
        if (!$user) abort(401);

        $isStaff = $user->role && in_array($user->role->slug, ['admin', 'support']);
        if (!$isStaff) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Stamp orders compose their convert_label from the address instead
        // of a carrier label, so they're a valid reconvert target too.
        $isStamp = $order->ship_type === Order::SHIP_STAMP;
        if (empty($order->shipping_label) && !$isStamp) {
            return response()->json(['message' => 'Order has no shipping_label (and isn\'t a stamp order) to reconvert'], 422);
        }
        if ($isStamp && !$order->loadMissing('address')->address) {
            return response()->json(['message' => 'Stamp order has no address to render the convert_label from'], 422);
        }

        $order->update(['convert_label' => null]);

        $note = $order->status === Order::STATUS_NEW_ORDER
            ? 'Reconvert queued — the converter cron will rebuild convert_label on its next poll.'
            : "convert_label cleared. Order {$order->system_id} is past new_order status, so the cron will skip it — use \"Convert by ID\" on the Convert Label page to force a rebuild.";

        return response()->json([
            'message' => $note,
            'order' => $order->fresh()->makeVisible('convert_label')
                ->only(['id', 'system_id', 'status', 'shipping_label', 'convert_label']),
        ]);
    }

    /**
     * Bulk-clear convert_label for many orders at once. Body: order_ids[].
     * Admin/support only.
     */
    public function bulkReconvertLabel(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $user = $request->user();
        $isStaff = $user->role && in_array($user->role->slug, ['admin', 'support']);
        if (!$isStaff) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Accept both carrier-label orders AND stamp orders (which render
        // the convert_label from their address instead of a carrier label).
        $orders = Order::whereIn('id', $request->order_ids)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('shipping_label')->where('shipping_label', '!=', '');
                })->orWhere('ship_type', Order::SHIP_STAMP);
            })
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'message' => 'No matching orders to reconvert (need shipping_label OR ship_type=stamp)',
                'count' => 0,
            ], 422);
        }

        $count = 0;
        foreach ($orders as $order) {
            $order->update(['convert_label' => null]);
            $count++;
        }

        return response()->json([
            'message' => "Cleared convert_label on {$count} order(s). The converter cron will rebuild them for orders still in new_order status.",
            'count' => $count,
        ]);
    }

    public function updateStatus(Request $request, Order $order)
    {
        $request->validate(['status' => 'required|integer|in:0,1,2,3,4,5,6,7']);

        $user = $request->user();
        if ($user && $user->hasRole('seller')
            && !in_array((int) $request->status, [Order::STATUS_ONHOLD, Order::STATUS_CANCELLED], true)) {
            return response()->json(['message' => 'Sellers can only set status to onhold or cancelled'], 403);
        }
        // A shipped order is final for sellers — they can't cancel/un-ship it.
        if ($user && $user->hasRole('seller')
            && (int) $order->status === Order::STATUS_SHIPPED
            && (int) $request->status !== Order::STATUS_SHIPPED) {
            return response()->json(['message' => 'Đơn đã shipped — seller không thể huỷ/đổi trạng thái. Liên hệ admin.'], 403);
        }

        $data = ['status' => $request->status];
        if ($request->status == Order::STATUS_SHIPPED) {
            $data['completed_time'] = now();
        }

        $order->update($data);

        $request->status == Order::STATUS_SHIPPED
            ? $order->fresh()->decrementStockForShipment()
            : $order->fresh()->restockForUnship();   // rời shipped → trả lại kho

        return response()->json(['message' => 'Status updated', 'order' => $order->fresh()]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'status' => 'required|integer|in:0,1,2,3,4,5,6,7',
        ]);

        $user = $request->user();
        if ($user && $user->hasRole('seller')
            && !in_array((int) $request->status, [Order::STATUS_ONHOLD, Order::STATUS_CANCELLED], true)) {
            return response()->json(['message' => 'Sellers can only set status to onhold or cancelled'], 403);
        }

        // Sellers can't cancel/un-ship a shipped order — skip any shipped ones
        // from their selection and process the rest. (Setting → shipped is fine.)
        $orderIds = $request->order_ids;
        $skippedShipped = 0;
        if ($user && $user->hasRole('seller') && (int) $request->status !== Order::STATUS_SHIPPED) {
            $shippedIds = Order::whereIn('id', $orderIds)
                ->where('status', Order::STATUS_SHIPPED)
                ->pluck('id')->all();
            $skippedShipped = count($shippedIds);
            $orderIds = array_values(array_diff($orderIds, $shippedIds));
            if (empty($orderIds)) {
                return response()->json([
                    'message' => 'Đơn đã shipped — seller không thể huỷ/đổi trạng thái. Liên hệ admin.',
                    'count' => 0,
                    'skipped_shipped' => $skippedShipped,
                ], 403);
            }
        }

        $data = ['status' => $request->status];
        if ($request->status == Order::STATUS_SHIPPED) {
            $data['completed_time'] = now();
        }

        Order::whereIn('id', $orderIds)->update($data);

        if ($request->status == Order::STATUS_SHIPPED) {
            // Decrement stock per-order (each call is idempotent via
            // stock_decremented), so re-shipping orders in a bulk batch is safe.
            Order::with('items')->whereIn('id', $orderIds)->get()
                ->each(fn ($o) => $o->decrementStockForShipment());
        } else {
            // Leaving shipped → return label stock for the ones that had it
            // deducted. The flag guard keeps never-shipped orders untouched.
            Order::whereIn('id', $orderIds)->where('stock_decremented', true)->get()
                ->each(fn ($o) => $o->restockForUnship());
        }

        return response()->json([
            'message' => $skippedShipped > 0
                ? "Đã cập nhật " . count($orderIds) . " đơn; bỏ qua {$skippedShipped} đơn đã shipped (seller không được huỷ)."
                : 'Bulk status updated',
            'count' => count($orderIds),
            'skipped_shipped' => $skippedShipped,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/orders/{order}/pay",
     *     tags={"Orders"},
     *     summary="Pay order from wallet",
     *     description="Trừ (total_cost - paid_cost) khỏi ví seller. Idempotent: đơn đã paid trả 400.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="order", in="path", required=true, @OA\Schema(type="integer"), example=123),
     *     @OA\Response(response=200, description="Paid"),
     *     @OA\Response(response=400, description="Already paid or insufficient wallet")
     * )
     */
    public function pay(Request $request, Order $order)
    {
        $user = $order->user;

        if ($order->paid_cost >= $order->total_cost) {
            return response()->json(['message' => 'Order already paid'], 400);
        }

        // Round to cents — float math on decimal columns can produce
        // values like 54.400000000000006 from summing line prices, which
        // would falsely trip the wallet < amount comparison below.
        $amountToPay = round((float) ($order->total_cost - $order->paid_cost), 2);

        if (round((float) $user->wallet, 2) < $amountToPay) {
            return response()->json(['message' => 'Insufficient wallet balance', 'required' => $amountToPay, 'wallet' => (float) $user->wallet], 400);
        }

        return DB::transaction(function () use ($user, $order, $amountToPay) {
            $balanceBefore = (float) $user->wallet;
            $user->decrement('wallet', $amountToPay);
            // Auto-advance new_order → producing once it's fully paid, so
            // fulfillers can see the queue without a separate status flip.
            $updates = ['paid_cost' => $order->total_cost];
            if ((int) $order->status === Order::STATUS_NEW_ORDER) {
                $updates['status'] = Order::STATUS_PROCESSING;
            }
            $order->update($updates);

            WalletTransaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'order_system_id' => $order->system_id,
                'type' => 'paid',
                'status' => WalletTransaction::STATUS_APPROVED,
                'amount' => -$amountToPay,
                'balance_before' => $balanceBefore,
                'balance_after' => $user->fresh()->wallet,
                'note' => "Paid order #{$order->system_id}",
            ]);

            return response()->json([
                'message' => 'Order paid',
                'order' => $order->fresh(),
                'wallet' => $user->fresh()->wallet,
            ]);
        });
    }

    public function unpaidSummary(Request $request)
    {
        $user = $request->user();
        $targetUserId = $user->hasRole('seller')
            ? $user->id
            : ((int) ($request->user_id ?? $user->id));

        $payer = $targetUserId === $user->id ? $user : \App\Models\User::find($targetUserId);
        if (!$payer) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $unpaid = Order::where('user_id', $targetUserId)
            ->whereColumn('paid_cost', '<', 'total_cost')
            ->get(['id', 'total_cost', 'paid_cost']);

        $count = $unpaid->count();
        $totalUnpaid = (float) $unpaid->sum(fn($o) => max(0, $o->total_cost - $o->paid_cost));
        $wallet = (float) $payer->wallet;
        $short = max(0, $totalUnpaid - $wallet);

        return response()->json([
            'user_id' => $targetUserId,
            'user_name' => $payer->name,
            'user_email' => $payer->email,
            'count' => $count,
            'total_unpaid' => $totalUnpaid,
            'wallet' => $wallet,
            'short' => $short,
        ]);
    }

    /**
     * App-side cron endpoint. The seller's renderer polls this every minute
     * while their window is open and auto_pay is on. We settle ONLY the
     * caller's own unpaid orders, oldest-first, stopping when the wallet
     * can't cover the next one (strict FIFO — no skipping ahead). Mirrors
     * the artisan command's per-user logic so the trail is identical.
     */
    public function autoPayRun(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->auto_pay) {
            return response()->json(['message' => 'Auto-pay is off', 'count' => 0], 200);
        }

        $count = 0;
        $totalAmount = 0.0;
        $systemIds = [];

        // Seller-configurable buffer: skip orders younger than N hours so the
        // seller still has a window to edit/cancel before money leaves the
        // wallet. 0 = pay immediately (legacy behaviour).
        $delayHours = max(0, (int) $user->auto_pay_delay_hours);
        $cutoff = $delayHours > 0 ? now()->subHours($delayHours) : null;

        while (true) {
            $q = Order::where('user_id', $user->id)
                ->whereColumn('paid_cost', '<', 'total_cost');
            if ($cutoff) {
                $q->where('created_at', '<=', $cutoff);
            }
            $order = $q->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->first();
            if (!$order) break;

            $remaining = round((float) $order->total_cost - (float) $order->paid_cost, 2);
            if ($remaining <= 0) break;

            $user->refresh();
            if (round((float) $user->wallet, 2) < $remaining) break;

            DB::transaction(function () use ($user, $order, $remaining) {
                $balanceBefore = (float) $user->wallet;
                $user->decrement('wallet', $remaining);

                $updates = ['paid_cost' => $order->total_cost];
                if ((int) $order->status === Order::STATUS_NEW_ORDER) {
                    $updates['status'] = Order::STATUS_PROCESSING;
                }
                $order->update($updates);

                WalletTransaction::create([
                    'user_id'         => $user->id,
                    'order_id'        => $order->id,
                    'order_system_id' => $order->system_id,
                    'type'            => 'paid',
                    'status'          => WalletTransaction::STATUS_APPROVED,
                    'amount'          => -$remaining,
                    'balance_before'  => $balanceBefore,
                    'balance_after'   => $user->fresh()->wallet,
                    'note'            => "Auto-paid order #{$order->system_id}",
                ]);
            });

            $count++;
            $totalAmount += $remaining;
            $systemIds[] = $order->system_id;
        }

        if ($count > 0) {
            app(\App\Services\TelegramService::class)->notifyTopup(implode("\n", [
                '🤖 <b>Auto-pay ran</b>',
                sprintf('User: %s (#%s)', e($user->name), $user->id),
                sprintf('Paid: <b>%s order(s)</b> · $%s', $count, number_format($totalAmount, 2)),
                sprintf('Wallet now: $%s', number_format((float) $user->fresh()->wallet, 2)),
                'Orders: ' . implode(', ', $systemIds),
            ]));
        }

        return response()->json([
            'count'      => $count,
            'amount'     => round($totalAmount, 2),
            'system_ids' => $systemIds,
            'wallet'     => (float) $user->fresh()->wallet,
        ]);
    }

    /**
     * Admin dashboard for the auto-pay system. Returns:
     *   - users: every seller with auto_pay=true + wallet + # unpaid + oldest
     *   - recent: last N auto-paid wallet_transactions (note LIKE 'Auto-paid%')
     * The desktop admin renders this in the Auto-pay menu.
     */
    public function autoPayStatus(Request $request)
    {
        $user = $request->user();
        if (!($user && ($user->hasRole('admin') || $user->hasRole('support')))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $users = User::where('auto_pay', true)
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'wallet', 'tier_id', 'auto_pay_delay_hours'])
            ->map(function ($u) {
                $unpaid = Order::where('user_id', $u->id)
                    ->whereColumn('paid_cost', '<', 'total_cost')
                    ->orderBy('created_at', 'asc');
                $count = (clone $unpaid)->count();
                $first = (clone $unpaid)->first(['id', 'system_id', 'total_cost', 'paid_cost', 'created_at']);
                $totalOwed = (float) (clone $unpaid)
                    ->get(['total_cost', 'paid_cost'])
                    ->sum(fn($o) => max(0, $o->total_cost - $o->paid_cost));
                return [
                    'id'                   => $u->id,
                    'name'                 => $u->name,
                    'email'                => $u->email,
                    'wallet'               => (float) $u->wallet,
                    'auto_pay_delay_hours' => (int) $u->auto_pay_delay_hours,
                    'unpaid'               => $count,
                    'owed'                 => round($totalOwed, 2),
                    'oldest'               => $first ? [
                        'id'         => $first->id,
                        'system_id'  => $first->system_id,
                        'remaining'  => round((float) $first->total_cost - (float) $first->paid_cost, 2),
                        'created_at' => $first->created_at,
                    ] : null,
                ];
            });

        $limit = max(10, min(200, (int) ($request->limit ?? 50)));
        $recent = WalletTransaction::where('note', 'like', 'Auto-paid order #%')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'user_id', 'order_id', 'order_system_id', 'amount', 'balance_after', 'created_at']);
        // Attach user name for display
        $userMap = User::whereIn('id', $recent->pluck('user_id')->unique())
            ->get(['id', 'name'])
            ->keyBy('id');
        $recent = $recent->map(function ($t) use ($userMap) {
            return [
                'id'              => $t->id,
                'user_id'         => $t->user_id,
                'user_name'       => $userMap[$t->user_id]->name ?? null,
                'order_id'        => $t->order_id,
                'order_system_id' => $t->order_system_id,
                'amount'          => (float) $t->amount,
                'balance_after'   => (float) $t->balance_after,
                'created_at'      => $t->created_at,
            ];
        });

        return response()->json(['users' => $users, 'recent' => $recent]);
    }

    /**
     * Admin force-runs auto-pay for one seller (ignores their auto_pay
     * flag — useful for manual ops). Returns the same shape as the
     * per-caller autoPayRun endpoint.
     */
    public function autoPayRunFor(Request $request, User $user)
    {
        $caller = $request->user();
        if (!($caller && ($caller->hasRole('admin') || $caller->hasRole('support')))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $count = 0;
        $totalAmount = 0.0;
        $systemIds = [];

        while (true) {
            $order = Order::where('user_id', $user->id)
                ->whereColumn('paid_cost', '<', 'total_cost')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->first();
            if (!$order) break;

            $remaining = round((float) $order->total_cost - (float) $order->paid_cost, 2);
            if ($remaining <= 0) break;

            $user->refresh();
            if (round((float) $user->wallet, 2) < $remaining) break;

            DB::transaction(function () use ($user, $order, $remaining) {
                $balanceBefore = (float) $user->wallet;
                $user->decrement('wallet', $remaining);
                $updates = ['paid_cost' => $order->total_cost];
                if ((int) $order->status === Order::STATUS_NEW_ORDER) {
                    $updates['status'] = Order::STATUS_PROCESSING;
                }
                $order->update($updates);

                WalletTransaction::create([
                    'user_id'         => $user->id,
                    'order_id'        => $order->id,
                    'order_system_id' => $order->system_id,
                    'type'            => 'paid',
                    'status'          => WalletTransaction::STATUS_APPROVED,
                    'amount'          => -$remaining,
                    'balance_before'  => $balanceBefore,
                    'balance_after'   => $user->fresh()->wallet,
                    'note'            => "Auto-paid order #{$order->system_id}",
                ]);
            });

            $count++;
            $totalAmount += $remaining;
            $systemIds[] = $order->system_id;
        }

        return response()->json([
            'count'      => $count,
            'amount'     => round($totalAmount, 2),
            'system_ids' => $systemIds,
            'wallet'     => (float) $user->fresh()->wallet,
        ]);
    }

    public function payAllUnpaid(Request $request)
    {
        $user = $request->user();

        // Sellers pay their own; admin/support can target a specific user via ?user_id.
        $targetUserId = $user->hasRole('seller')
            ? $user->id
            : ((int) ($request->user_id ?? $user->id));

        $orders = Order::where('user_id', $targetUserId)
            ->whereColumn('paid_cost', '<', 'total_cost')
            ->get();

        $totalToPay = round((float) $orders->sum(fn($o) => max(0, (float) $o->total_cost - (float) $o->paid_cost)), 2);

        if ($orders->isEmpty() || $totalToPay <= 0) {
            return response()->json(['message' => 'No unpaid orders to pay', 'count' => 0], 400);
        }

        $payer = $targetUserId === $user->id ? $user : \App\Models\User::findOrFail($targetUserId);
        if (round((float) $payer->wallet, 2) < $totalToPay) {
            return response()->json([
                'message' => 'Insufficient wallet balance',
                'required' => $totalToPay,
                'wallet' => (float) $payer->wallet,
                'unpaid_count' => $orders->count(),
            ], 400);
        }

        return DB::transaction(function () use ($payer, $orders, $totalToPay) {
            $paidCount = 0;
            foreach ($orders as $order) {
                $amountToPay = $order->total_cost - $order->paid_cost;
                if ($amountToPay <= 0) continue;

                $balanceBefore = (float) $payer->wallet;
                $payer->decrement('wallet', $amountToPay);
                $updates = ['paid_cost' => $order->total_cost];
                if ((int) $order->status === Order::STATUS_NEW_ORDER) {
                    $updates['status'] = Order::STATUS_PROCESSING;
                }
                $order->update($updates);

                WalletTransaction::create([
                    'user_id' => $payer->id,
                    'order_id' => $order->id,
                    'order_system_id' => $order->system_id,
                    'type' => 'paid',
                    'status' => WalletTransaction::STATUS_APPROVED,
                    'amount' => -$amountToPay,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $payer->fresh()->wallet,
                    'note' => "Paid order #{$order->system_id}",
                ]);
                $paidCount++;
            }

            return response()->json([
                'message' => "Paid {$paidCount} order(s)",
                'count' => $paidCount,
                'total_paid' => $totalToPay,
                'wallet' => $payer->fresh()->wallet,
            ]);
        });
    }

    /**
     * @OA\Post(
     *     path="/orders/bulk-pay",
     *     tags={"Orders"},
     *     summary="Bulk pay orders from wallet",
     *     description="Pay nhiều đơn cùng lúc từ ví owner. Atomic — nếu owner thiếu ví, abort toàn batch không trừ ai.",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"order_ids"},
     *             @OA\Property(property="order_ids", type="array", @OA\Items(type="integer"), example={123,124,125})
     *         )
     *     ),
     *     @OA\Response(response=200, description="Paid"),
     *     @OA\Response(response=400, description="Insufficient wallet or all paid")
     * )
     */
    public function bulkPay(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
        ]);

        $caller = $request->user();
        $query = Order::whereIn('id', $request->order_ids);
        if ($caller->hasRole('seller')) {
            // Sellers can only bulk-pay their own orders.
            $query->where('user_id', $caller->id);
        }
        $orders = $query->get();

        // Group unpaid orders by owner. Each owner is charged from THEIR OWN
        // wallet, so the same caller (e.g. admin) can pay across multiple
        // sellers in one request without ever touching the admin's wallet.
        $unpaidByOwner = $orders
            ->filter(fn($o) => $o->paid_cost < $o->total_cost)
            ->groupBy('user_id');

        if ($unpaidByOwner->isEmpty()) {
            return response()->json(['message' => 'All orders already paid'], 400);
        }

        $owners = \App\Models\User::whereIn('id', $unpaidByOwner->keys())->get()->keyBy('id');

        // Pre-flight: every owner must have enough wallet for THEIR slice. If
        // any single owner is short, abort the whole batch (otherwise we'd
        // partially pay some sellers and leave others in a weird state).
        $totalsByOwner = [];
        foreach ($unpaidByOwner as $ownerId => $ownerOrders) {
            $owner = $owners[$ownerId] ?? null;
            if (!$owner) {
                return response()->json(['message' => "Order owner #{$ownerId} not found"], 400);
            }
            $ownerTotal = round((float) $ownerOrders->sum(fn($o) => max(0, (float) $o->total_cost - (float) $o->paid_cost)), 2);
            if (round((float) $owner->wallet, 2) < $ownerTotal) {
                return response()->json([
                    'message' => "Insufficient wallet balance for {$owner->name}",
                    'user_id' => $owner->id,
                    'user_name' => $owner->name,
                    'required' => $ownerTotal,
                    'wallet' => (float) $owner->wallet,
                ], 400);
            }
            $totalsByOwner[$ownerId] = $ownerTotal;
        }

        return DB::transaction(function () use ($unpaidByOwner, $owners, $totalsByOwner) {
            $paidCount = 0;
            $totalPaid = 0;
            $walletsAfter = [];
            foreach ($unpaidByOwner as $ownerId => $ownerOrders) {
                $owner = $owners[$ownerId];
                foreach ($ownerOrders as $order) {
                    $amountToPay = $order->total_cost - $order->paid_cost;
                    if ($amountToPay <= 0) continue;

                    $balanceBefore = (float) $owner->wallet;
                    $owner->decrement('wallet', $amountToPay);
                    $updates = ['paid_cost' => $order->total_cost];
                    if ((int) $order->status === Order::STATUS_NEW_ORDER) {
                        $updates['status'] = Order::STATUS_PROCESSING;
                    }
                    $order->update($updates);

                    WalletTransaction::create([
                        'user_id' => $owner->id,
                        'order_id' => $order->id,
                        'order_system_id' => $order->system_id,
                        'type' => 'paid',
                        'status' => WalletTransaction::STATUS_APPROVED,
                        'amount' => -$amountToPay,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $owner->fresh()->wallet,
                        'note' => "Paid order #{$order->system_id}",
                    ]);
                    $paidCount++;
                    $totalPaid += $amountToPay;
                }
                $walletsAfter[$ownerId] = (float) $owner->fresh()->wallet;
            }

            return response()->json([
                'message' => "Paid {$paidCount} orders",
                'total_paid' => $totalPaid,
                'totals_by_owner' => $totalsByOwner,
                'wallets_after' => $walletsAfter,
            ]);
        });
    }

    /**
     * @OA\Post(
     *     path="/orders/import-csv",
     *     tags={"Orders"},
     *     summary="Import orders from CSV",
     *     description="Upload CSV để tạo nhiều đơn 1 lần. Download template tại GET /orders/export-csv-template. Hỗ trợ multi-accessory qua cột accessory_code (comma/pipe separated).",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file"},
     *                 @OA\Property(property="file",   type="string", format="binary", description="CSV file"),
     *                 @OA\Property(property="force",  type="boolean", default=false, description="Bỏ qua check 409 duplicate ref_id"),
     *                 @OA\Property(property="rename", type="boolean", default=false, description="Auto thêm suffix _r_1 nếu trùng")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Import result"),
     *     @OA\Response(response=409, description="Duplicate ref_id detected")
     * )
     */
    public function importCsv(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt']);

        $file = $request->file('file');
        $rows = array_map('str_getcsv', file($file->getPathname()));
        $header = array_map('trim', array_shift($rows));

        $user = $request->user();
        $created = [];
        $createdOrderIds = [];
        $errors = [];
        $force = $request->boolean('force');
        $rename = $request->boolean('rename');

        // Pre-scan for duplicate ref_ids against DB only. Multiple rows in the
        // file sharing the same ref_id are now grouped into a single order
        // (see processing pass below) — they are NOT duplicates.
        $refIdIdx = array_search('ref_id', $header, true);
        if ($refIdIdx !== false) {
            $csvRefIds = [];
            foreach ($rows as $row) {
                if (!is_array($row) || count($row) <= $refIdIdx) continue;
                $r = trim((string) ($row[$refIdIdx] ?? ''));
                if ($r !== '') $csvRefIds[] = $r;
            }
            if (!empty($csvRefIds)) {
                $dbDup = Order::where('user_id', $user->id)
                    ->whereIn('ref_id', array_values(array_unique($csvRefIds)))
                    ->pluck('ref_id')
                    ->unique()
                    ->values()
                    ->all();
                if (!empty($dbDup) && !$force) {
                    // Force string serialization. PHP auto-casts numeric strings to
                    // int when they become array keys, and json_encode then emits
                    // them unquoted — which loses precision in JavaScript for
                    // 18-digit ref_ids (Number max safe is 16 digits).
                    return response()->json([
                        'message' => 'Duplicate ref_id detected',
                        'duplicates' => array_map('strval', $dbDup),
                    ], 409);
                }
            }
        }

        $assignedInBatch = [];

        $response = DB::transaction(function () use ($rows, $header, $user, $force, $rename, &$assignedInBatch, &$created, &$createdOrderIds, &$errors) {
            // ref_id (after rename) → Order model already created in this batch.
            // Rows sharing a ref_id append items to the same order instead of
            // creating a new one.
            $batchOrders = [];

            foreach ($rows as $index => $row) {
                $lineNum = $index + 2;
                if (count($row) !== count($header)) {
                    $errors[] = "Line {$lineNum}: column count mismatch";
                    continue;
                }

                $data = array_combine($header, $row);

                // Resolve variant: prefer sku, fallback to product_variant_id
                $variantId = null;
                if (!empty($data['sku'])) {
                    $variant = ProductVariant::where('sku', $data['sku'])->first();
                    if (!$variant) {
                        $errors[] = "Line {$lineNum}: sku '{$data['sku']}' not found";
                        continue;
                    }
                    $variantId = $variant->id;
                } elseif (!empty($data['product_variant_id'])) {
                    $variantId = (int)$data['product_variant_id'];
                } else {
                    $errors[] = "Line {$lineNum}: missing sku (or product_variant_id)";
                    continue;
                }

                $quantity = max(1, (int)($data['quantity'] ?? 1));
                // ship_by_stamp column: 1/true/yes/x → stamp order (address-only).
                $byStamp = in_array(strtolower(trim((string) ($data['ship_by_stamp'] ?? ''))), ['1', 'true', 'yes', 'x', 'stamp'], true);
                $hasLabel = !$byStamp && !empty($data['shipping_label']);
                $hasAddress = !empty($data['first_name']) && !empty($data['address_1']) && !empty($data['city']) && !empty($data['zipcode']) && !empty($data['country']);

                $rawRef = !empty($data['ref_id']) ? trim($data['ref_id']) : null;
                if ($rawRef === null) {
                    $errors[] = "Line {$lineNum}: ref_id is required";
                    continue;
                }
                $existingOrder = isset($batchOrders[$rawRef]) ? $batchOrders[$rawRef] : null;

                // Address/shipping is only required for the FIRST row in a group.
                // Follow-up rows attach to the same order regardless of their
                // address/label cells.
                if (!$existingOrder && !$hasLabel && !$hasAddress) {
                    $errors[] = "Line {$lineNum}: must provide either shipping_label or full address (first_name, address_1, city, zipcode, country)";
                    continue;
                }

                // Resolve accessory price(s). accessory_code accepts a single
                // code or comma/pipe-separated list (e.g. "W,B,R") to attach
                // multiple accessories to the same line item. accessory_item_id
                // is kept for legacy CSVs that use the numeric id.
                $resolvedAccPrices = collect();
                $codeRaw = trim((string) ($data['accessory_code'] ?? ''));
                $idRaw = trim((string) ($data['accessory_item_id'] ?? ''));
                if ($codeRaw !== '') {
                    $codes = array_values(array_filter(array_map('trim', preg_split('/[\s,;|]+/', $codeRaw))));
                    foreach ($codes as $code) {
                        $accPrice = AccessoryPrice::where('accessory_code', $code)
                            ->where('tier_id', $user->tier_id)
                            ->first();
                        if (!$accPrice) {
                            $errors[] = "Line {$lineNum}: accessory_code '{$code}' not found for your tier (row skipped)";
                            $resolvedAccPrices = null;
                            break;
                        }
                        $resolvedAccPrices->push($accPrice);
                    }
                } elseif ($idRaw !== '') {
                    $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,;|]+/', $idRaw))));
                    foreach ($ids as $id) {
                        $accPrice = AccessoryPrice::find($id);
                        if (!$accPrice || $accPrice->tier_id !== $user->tier_id) {
                            $errors[] = "Line {$lineNum}: accessory_item_id {$id} not valid for your tier (row skipped)";
                            $resolvedAccPrices = null;
                            break;
                        }
                        $resolvedAccPrices->push($accPrice);
                    }
                }
                if ($resolvedAccPrices === null) continue; // any invalid → skip row entirely
                $accessoryItemId = $resolvedAccPrices->isNotEmpty() ? $resolvedAccPrices->first()->id : null;
                $accessoryUnitPrice = (float) $resolvedAccPrices->sum('price');

                if ($existingOrder) {
                    // Row joins an existing order in this batch — append item only.
                    $order = $existingOrder;
                } else {
                    // First row for this ref_id (or row without ref_id) → create new order.
                    $finalRef = $rawRef;
                    if ($rawRef !== null && $force && $rename) {
                        $finalRef = $this->generateUniqueRefId($user->id, $rawRef, $assignedInBatch);
                    }
                    if ($finalRef !== null) {
                        $assignedInBatch[$finalRef] = true;
                    }

                    $order = Order::create([
                        'user_id' => $user->id,
                        'system_id' => $this->generateSystemId(), // temporary
                        'ref_id' => $finalRef,
                        'status' => Order::STATUS_NEW_ORDER,
                        'ship_type' => $byStamp ? Order::SHIP_STAMP : ($hasLabel ? Order::SHIP_TIKTOK : Order::SHIP_SELLER),
                        'shipping_label' => $hasLabel ? $data['shipping_label'] : null,
                    ]);
                    $order->update(['system_id' => $this->buildSystemIdForOrder($order->id, $variantId)]);

                    if (!$hasLabel && $hasAddress) {
                        Address::create([
                            'order_id' => $order->id,
                            'first_name' => $data['first_name'],
                            'last_name' => $data['last_name'] ?? '',
                            'address_1' => $data['address_1'],
                            'address_2' => $data['address_2'] ?? null,
                            'city' => $data['city'],
                            'state' => $data['state'] ?? null,
                            'zipcode' => $data['zipcode'],
                            'country' => $data['country'],
                        ]);
                    }

                    if ($rawRef !== null) {
                        $batchOrders[$rawRef] = $order;
                    }
                    $created[] = $order->system_id;
                    $createdOrderIds[] = $order->id;
                }

                // Build the metas list up front so the per-item price can
                // include 2nd_fee for 2-sided designs (same rule as store()).
                $metasIn = [];
                foreach (['front', 'back', 'left', 'right', 'neck', 'special'] as $metaKey) {
                    $col = 'meta_' . $metaKey;
                    if (!empty($data[$col])) {
                        $metasIn[] = ['key' => $metaKey, 'value' => $data[$col]];
                    }
                }
                $price = $this->computeItemPrice($metasIn, $variantId, $user->tier_id);

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $variantId,
                    'accessory_item_id' => $accessoryItemId,
                    // Material (chất liệu): use the CSV value when given, else fall
                    // back to the variant's default material (is_default) — same
                    // rule as the manual create form, so imported rows always get
                    // a material instead of null.
                    'material_id' => $this->resolveMaterialId(
                        (isset($data['material_id']) && $data['material_id'] !== '') ? (int) $data['material_id'] : null,
                        $variantId
                    ),
                    'mockup_front' => $data['mockup_front'] ?? null,
                    'mockup_back' => $data['mockup_back'] ?? null,
                    'order_type' => (int)($data['order_type'] ?? 0),
                    'order_type_label' => $this->productOrderType((int) $variantId),
                    'price' => $price,
                    'quantity' => $quantity,
                ]);
                if ($resolvedAccPrices->isNotEmpty()) {
                    $item->accessoryPrices()->sync($resolvedAccPrices->pluck('id')->all());
                }

                foreach ($metasIn as $m) {
                    OrderItemMeta::create([
                        'order_item_id' => $item->id,
                        'key' => $m['key'],
                        'value' => $m['value'],
                    ]);
                }

                // Recompute order totals across ALL items now in the order so a
                // grouped order's total reflects every appended row.
                $orderHasLabel = !empty($order->shipping_label);
                $items = $order->items()->with('accessoryPrices')->get();
                $totalQty = 0;
                $totalPrint = 0;
                foreach ($items as $it) {
                    $itQty = max(1, (int) ($it->quantity ?? 1));
                    $itAcc = (float) $it->accessoryPrices->sum('price');
                    $totalPrint += ((float) $it->price + $itAcc) * $itQty;
                    $totalQty += $itQty;
                }
                $firstItemVariantId = (int) ($items->first()->product_variant_id ?? $variantId);
                if ($order->ship_type === Order::SHIP_STAMP) {
                    $stampMax = Order::stampConfig()['max_items'];
                    if ($totalQty > $stampMax) {
                        $errors[] = "Order {$order->system_id}: stamp vượt {$stampMax} thiệp ({$totalQty}) — nên dùng label thường.";
                    }
                    $shippingCost = Order::stampShippingCost($totalQty);
                } else {
                    $shippingCost = $this->computeOrderShipping($firstItemVariantId, $user->tier_id, $orderHasLabel, $totalQty);
                }
                $order->update([
                    'print_cost' => $totalPrint,
                    'shipping_cost' => $shippingCost,
                    'total_cost' => $totalPrint + $shippingCost,
                ]);
            }

            return response()->json([
                'message' => 'CSV import completed',
                'created_count' => count($created),
                'error_count' => count($errors),
                'created_refs' => $created,
                'created_ids' => $createdOrderIds,
                'errors' => $errors,
            ]);
        });

        $this->refreshDuplicateRefIds();

        // Image-URL validation for imported orders runs in the desktop client:
        // the import response returns created_ids, and the client validates +
        // saves results (see services/importUrlCheck.jsx). Nothing to dispatch
        // server-side — keeps the request fast and needs no queue worker.

        // Best-effort single summary notification for the bulk import.
        try {
            $count = (int) ($response->getData()->created_count ?? 0);
            if ($count > 0) {
                app(\App\Services\TelegramService::class)->notifyOrdersImported($request->user(), $count);
            }
        } catch (\Throwable $e) {
            \Log::warning('[telegram] notifyOrdersImported: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * GET /api/orders/invoice-data — aggregate selected/filtered orders into
     * the JSON payload the desktop app needs to render a commercial invoice
     * PDF. Same filter shape as exportOrders. PDF rendering happens in the
     * Electron renderer (jsPDF), so this endpoint never produces a binary —
     * just the structured data.
     *
     * Aggregation key: (product_id, paper_type). One invoice line per
     * product+material combo, summing quantity across all matched orders.
     */
    /**
     * @OA\Get(
     *     path="/orders/invoice-data",
     *     tags={"Orders"},
     *     summary="Invoice aggregation data",
     *     description="JSON payload cho invoice PDF. **Cùng bộ filter** như GET /orders (kể cả product filters).",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status",             in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="date_from",          in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to",            in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="paid",               in="query", @OA\Schema(type="string", enum={"paid","unpaid"})),
     *     @OA\Parameter(name="user_id",            in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="product_id",         in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="product_ids",        in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="product_variant_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="line_id",            in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sku",                in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="color",              in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="size",               in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="paper_type",         in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="material_id",        in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="accessory_id",       in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="order_ids",          in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Invoice JSON"),
     *     @OA\Response(response=404, description="No orders matched")
     * )
     */
    public function invoiceData(Request $request)
    {
        $query = Order::with([
            'items.productVariant.product',
            'user:id,name,email',
        ]);

        if ($request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user()->id);
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $this->applyOrderListFilters($query, $request);

        $orders = $query->orderBy('id', 'asc')->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No orders matched the filter'], 404);
        }

        // Group items across all matched orders by (product_id, paper_type).
        // Each bucket sums quantity + subtotal so the invoice shows one row
        // per product+material rather than one per order.
        $buckets = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $variant = $item->productVariant;
                if (!$variant) continue;
                $product = $variant->product;
                $material = trim((string) ($variant->paper_type ?? ''));
                $key = ($product?->id ?? 0) . '|' . $material;
                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'product_id'    => $product?->id,
                        'sku'           => $variant->sku,
                        'product_name'  => trim(($product?->name ?? 'Item') . ' - Print on Demand'),
                        // No `brand` column yet — fall back to product.style
                        // (currently free-form) so admin can set it via the
                        // product edit page without a migration.
                        'brand'         => trim((string) ($product?->style ?? '')),
                        'materials'     => $material,
                        'total_items'   => 0,
                        'subtotal'      => 0.0,
                    ];
                }
                $qty = max(1, (int) $item->quantity);
                $buckets[$key]['total_items'] += $qty;
                $buckets[$key]['subtotal']    += (float) $item->price * $qty;
            }
        }

        // Compute unit print_cost per bucket (subtotal / total_items, rounded).
        $lineItems = [];
        $i = 1;
        foreach ($buckets as $row) {
            $unit = $row['total_items'] > 0 ? $row['subtotal'] / $row['total_items'] : 0;
            $lineItems[] = [
                'item'         => $i++,
                'sku'          => $row['sku'],
                'product_name' => $row['product_name'],
                'brand'        => $row['brand'],
                'materials'    => $row['materials'],
                'total_items'  => $row['total_items'],
                'print_cost'   => round($unit, 2),
                'subtotal'     => round($row['subtotal'], 2),
            ];
        }

        $printTotal    = round($orders->sum('print_cost'), 2);
        $shippingTotal = round($orders->sum('shipping_cost'), 2);
        $grandTotal    = round($printTotal + $shippingTotal, 2);

        $customer = $orders->first()->user;
        // Invoice # — simple, stable per generation. {customerInitials}{YYMMDDHHmm}
        $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $customer->name ?? 'INV'), 0, 2)) ?: 'INV';
        $invoiceNumber = $initials . now()->format('ymdHi');

        $dates = $orders->pluck('created_at');
        $dateFrom = $dates->min()?->format('M j Y');
        $dateTo   = $dates->max()?->format('M j Y');
        $dateLabel = ($dateFrom === $dateTo) ? $dateFrom : "{$dateFrom} – {$dateTo}";

        return response()->json([
            'invoice_number' => $invoiceNumber,
            'date_label'     => $dateLabel,
            'date_from'      => $dates->min()?->toDateString(),
            'date_to'        => $dates->max()?->toDateString(),
            'customer'       => [
                'id'    => $customer?->id,
                'name'  => $customer?->name,
                'email' => $customer?->email,
            ],
            'line_items' => $lineItems,
            'totals' => [
                'print_cost'    => $printTotal,
                'shipping_cost' => $shippingTotal,
                'total'         => $grandTotal,
            ],
            'order_count' => $orders->count(),
            'order_ids'   => $orders->pluck('id')->all(),
            // Payment info — the renderer picks pingpong or vnpay depending
            // on which button the admin clicked. Always include both so the
            // app doesn't need a second round-trip when switching variants.
            'payment_settings' => [
                'pingpong' => \App\Models\AppSetting::getJson('invoice_pingpong', []),
                // Inject the USD→VND exchange rate so the VNPay invoice can print
                // the converted VND amount next to the USD total.
                'vnpay'    => array_merge(
                    \App\Models\AppSetting::getJson('invoice_vnpay', []),
                    ['vnd_per_usd' => (float) (\App\Models\AppSetting::getJson('wallet_exchange_rate', [])['vnd_per_usd'] ?? config('services.vnpay.rate'))]
                ),
            ],
        ]);
    }

    public function exportCsvTemplate()
    {
        $headers = [
            'ref_id',
            'sku', 'quantity',
            'mockup_front', 'mockup_back',
            'accessory_code',
            'shipping_label',
            'meta_front', 'meta_back', 'meta_left', 'meta_right', 'meta_neck', 'meta_special',
        ];

        $callback = function () use ($headers) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);
            fputcsv($file, [
                'CUSTOMER-REF-001',
                'SKU-RED-S', '1',
                'https://drive.google.com/file/d/FRONT_ID/view', 'https://drive.google.com/file/d/BACK_ID/view',
                '',
                'https://drive.google.com/file/d/LABEL_ID/view',
                'https://drive.google.com/file/d/META_FRONT_ID/view', '', '', '', '', '',
            ]);
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="order_template.csv"',
        ]);
    }

    /**
     * GET /api/orders/export-stamp-csv-template — template for SHIP-BY-STAMP
     * bulk import. Uses the address columns (no shipping_label) + the
     * ship_by_stamp flag. importCsv reads the same columns.
     */
    public function exportStampCsvTemplate()
    {
        $headers = [
            'ref_id',
            'sku', 'quantity',
            'mockup_front', 'mockup_back',
            'accessory_code',
            'ship_by_stamp',
            'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'zipcode', 'country',
            'meta_front', 'meta_back', 'meta_left', 'meta_right', 'meta_neck', 'meta_special',
        ];

        $callback = function () use ($headers) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);
            // Example stamp row — ship_by_stamp=1, address filled, no label.
            // Max items per stamp order is enforced server-side (Settings → Stamp).
            fputcsv($file, [
                'STAMP-REF-001',
                'SKU-RED-S', '1',
                'https://drive.google.com/file/d/FRONT_ID/view', '',
                '',
                '1',
                'John', 'Doe', '123 Main St', 'Apt 4', 'Austin', 'TX', '78701', 'US',
                'https://drive.google.com/file/d/META_FRONT_ID/view', '', '', '', '', '',
            ]);
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="order_stamp_template.csv"',
        ]);
    }

    /**
     * @OA\Get(
     *     path="/orders/export",
     *     tags={"Orders"},
     *     summary="Export orders to CSV",
     *     description="Streamed CSV với **cùng filter** như GET /orders (kể cả product filters). Hoặc truyền order_ids để export riêng các đơn đã chọn.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status",             in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="ship_type",          in="query", @OA\Schema(type="string", enum={"tiktok_ship","seller_ship"})),
     *     @OA\Parameter(name="ref_id",             in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="ref_ids",            in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="system_id",          in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="tracking_id",        in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="tracking_status",    in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="date_from",          in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to",            in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="paid",               in="query", @OA\Schema(type="string", enum={"paid","unpaid"})),
     *     @OA\Parameter(name="user_id",            in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="product_id",         in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="product_ids",        in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="product_variant_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="line_id",            in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sku",                in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="color",              in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="size",               in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="paper_type",         in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="material_id",        in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="accessory_id",       in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="order_ids",          in="query", description="Comma-separated", @OA\Schema(type="string"), example="123,124,125"),
     *     @OA\Response(response=200, description="CSV file", @OA\MediaType(mediaType="text/csv"))
     * )
     */
    public function exportOrders(Request $request)
    {
        $query = Order::with(['items.productVariant', 'address', 'user']);

        if ($request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user()->id);
        }

        // Mirror /orders index filters so export reflects what admin is seeing.
        if ($request->has('user_id') && !$request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user_id);
        }
        $this->applyOrderListFilters($query, $request);

        $orders = $query->orderBy('id', 'desc')->get();

        $callback = function () use ($orders) {
            $file = fopen('php://output', 'w');
            // BOM so Excel reads UTF-8 correctly (vietnamese names in addresses).
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['system_id', 'ref_id', 'status', 'ship_type', 'tracking_id', 'total_quantity', 'print_cost', 'shipping_cost', 'total_cost', 'paid_cost', 'first_name', 'last_name', 'address_1', 'city', 'state', 'zipcode', 'country', 'created_at']);

            $totalOrders = 0;
            $totalItems  = 0;
            $totalAmount = 0.0;
            foreach ($orders as $order) {
                $addr = $order->address;
                $totalQty = $order->items->sum(fn($i) => max(1, (int) ($i->quantity ?? 1)));
                fputcsv($file, [
                    $order->system_id,
                    $order->ref_id,
                    Order::STATUS_MAP[$order->status] ?? 'unknown',
                    $order->ship_type,
                    $order->tracking_id,
                    $totalQty,
                    $order->print_cost,
                    $order->shipping_cost,
                    $order->total_cost,
                    $order->paid_cost,
                    $addr->first_name ?? '',
                    $addr->last_name ?? '',
                    $addr->address_1 ?? '',
                    $addr->city ?? '',
                    $addr->state ?? '',
                    $addr->zipcode ?? '',
                    $addr->country ?? '',
                    $order->created_at->toDateTimeString(),
                ]);
                $totalOrders++;
                $totalItems  += $totalQty;
                $totalAmount += (float) $order->total_cost;
            }

            // Summary totals at the bottom (blank row first as a separator).
            fputcsv($file, []);
            fputcsv($file, ['Total Order', $totalOrders]);
            fputcsv($file, ['Total Item', $totalItems]);
            fputcsv($file, ['Total Amount', number_format($totalAmount, 2, '.', '')]);
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="orders_export_' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Buy a Shippo shipping label for a seller_ship order. Admin/support or the
     * order's own seller. The full Shippo response is stored on the order's
     * address (shipping_json) and label_url/tracking copied onto the order.
     */
    public function buyLabel(Request $request, Order $order)
    {
        $user = $request->user();
        $isStaff = $user->hasRole('admin') || $user->hasRole('support');
        if (!$isStaff && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = app(\App\Services\ShippoService::class)->buyLabelForOrder($order->load('address'));
        if (!($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }
        return response()->json(array_merge($result, [
            'order' => $order->fresh()->only(['id', 'system_id', 'shipping_label', 'tracking_id']),
        ]));
    }

    /**
     * seller_ship orders that still need a label (no shipping_label yet) and
     * have a recipient address. The app-side auto-buy cron polls this with
     * ?auto=1 to only include sellers who opted in (users.auto_buy_label).
     * Sellers are scoped to their own orders.
     */
    public function pendingLabels(Request $request)
    {
        $q = Order::where('ship_type', Order::SHIP_SELLER)
            ->where(function ($w) { $w->whereNull('shipping_label')->orWhere('shipping_label', ''); })
            ->whereHas('address')
            ->with('user:id,name,auto_buy_label');

        if ($request->user()->hasRole('seller')) {
            $q->where('user_id', $request->user()->id);
        }
        if ($request->boolean('auto')) {
            $q->whereHas('user', fn ($u) => $u->where('auto_buy_label', true));
        }

        $limit  = min(500, max(1, (int) $request->get('limit', 200)));
        $orders = $q->orderBy('id', 'asc')->limit($limit)->get(['id', 'system_id', 'user_id', 'ship_type']);

        return response()->json(['data' => $orders]);
    }

    /**
     * Public API (X-API-KEY): list products + variants with the API user's
     * tier prices. External systems use this to know which SKUs they can
     * pass to POST /api/fulfill and what each variant costs.
     */
    /**
     * @OA\Get(
     *     path="/catalog/products",
     *     tags={"Public API"},
     *     summary="List products + variants (X-API-KEY)",
     *     description="Catalog product với tier-scoped prices của caller. Dùng để biết product_variant_id trước khi POST /fulfill.",
     *     security={{"apiKeyAuth":{}}},
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=401, description="Invalid X-API-KEY")
     * )
     */
    public function productsByApiKey(Request $request)
    {
        $user = $request->get('api_user');
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
        $tierId = $user->tier_id;

        $products = \App\Models\Product::with([
            'variants' => fn ($q) => $q->where('status', 1),
            'variants.prices' => fn ($q) => $q->where('tier_id', $tierId),
        ])->where('status', 1)->get();

        // Flatten variant prices into a {key => price} map per variant for easier consumption.
        $products->each(function ($p) {
            $p->variants->each(function ($v) {
                $v->price_map = $v->prices->mapWithKeys(fn ($p) => [$p->key => (float) $p->price]);
                $v->makeHidden('prices');
            });
        });

        return response()->json([
            'tier_id' => $tierId,
            'data' => $products,
        ]);
    }

    /**
     * Public API: list accessories + tier-scoped prices. Returns the
     * accessory_code values the seller can pass when creating orders via
     * POST /api/fulfill.
     */
    /**
     * @OA\Get(
     *     path="/catalog/accessories",
     *     tags={"Public API"},
     *     summary="List accessories (X-API-KEY)",
     *     description="Accessory + tier-scoped prices. Dùng để biết accessory_ids[] trước khi POST /fulfill.",
     *     security={{"apiKeyAuth":{}}},
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function accessoriesByApiKey(Request $request)
    {
        $user = $request->get('api_user');
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
        $tierId = $user->tier_id;

        $rows = \App\Models\AccessoryPrice::with('accessory:id,name')
            ->where('tier_id', $tierId)
            ->get(['id', 'accessory_id', 'tier_id', 'style', 'accessory_code', 'price']);

        return response()->json([
            'tier_id' => $tierId,
            'data' => $rows,
        ]);
    }

    /**
     * Public API: list orders for the API key's user. Supports the same
     * filters as the desktop app (status, ref_id substring, ref_ids list,
     * system_id substring, date range, pagination).
     */
    /**
     * @OA\Get(
     *     path="/fulfill",
     *     tags={"Public API"},
     *     summary="List orders (X-API-KEY)",
     *     description="Liệt kê đơn của caller. Filter giống GET /orders (kể cả product filters).",
     *     security={{"apiKeyAuth":{}}},
     *     @OA\Parameter(name="status",             in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="ship_type",          in="query", @OA\Schema(type="string", enum={"tiktok_ship","seller_ship"})),
     *     @OA\Parameter(name="ref_id",             in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="ref_ids",            in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="system_id",          in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="tracking_id",        in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="date_from",          in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to",            in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="paid",               in="query", @OA\Schema(type="string", enum={"paid","unpaid"})),
     *     @OA\Parameter(name="product_id",         in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="product_ids",        in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="product_variant_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="line_id",            in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sku",                in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="color",              in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="size",               in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="paper_type",         in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="material_id",        in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="accessory_id",       in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page",           in="query", @OA\Schema(type="integer", default=50)),
     *     @OA\Response(response=200, description="Paginated list")
     * )
     */
    public function indexByApiKey(Request $request)
    {
        $user = $request->get('api_user');
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        $query = Order::with(['items.metas', 'items.productVariant.product', 'items.material', 'items.accessoryPrice', 'address'])
            ->where('user_id', $user->id);

        $this->applyOrderListFilters($query, $request);

        $perPage = min(200, max(1, (int) $request->get('per_page', 50)));
        return response()->json($query->orderBy('id', 'desc')->paginate($perPage));
    }

    /**
     * Public API: show a single order belonging to the API key's user.
     * Looks up by system_id first, then ref_id (so external sellers can call
     * either GET /api/fulfill/PS_C123 or GET /api/fulfill/MY-CUSTOM-REF).
     */
    /**
     * @OA\Get(
     *     path="/fulfill/{idOrRef}",
     *     tags={"Public API"},
     *     summary="Get order (X-API-KEY)",
     *     description="Numeric → order.id. String → order.ref_id.",
     *     security={{"apiKeyAuth":{}}},
     *     @OA\Parameter(name="idOrRef", in="path", required=true, @OA\Schema(type="string"), example="SHOP-987"),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function showByApiKey(Request $request, string $idOrRef)
    {
        $user = $request->get('api_user');
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        $order = Order::with([
            'items.metas',
            'items.productVariant.product',
            'items.accessoryPrice.accessory',
            'address',
        ])
        ->where('user_id', $user->id)
        ->where(function ($q) use ($idOrRef) {
            $q->where('system_id', $idOrRef)->orWhere('ref_id', $idOrRef);
        })
        ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return response()->json(['order' => $order]);
    }

    /**
     * @OA\Post(
     *     path="/fulfill",
     *     tags={"Public API"},
     *     summary="Create order (X-API-KEY)",
     *     description="Tạo đơn từ external system. Auth bằng header X-API-KEY (lấy từ Profile page hoặc GET /me/api-key). Body payload identical với POST /orders.",
     *     security={{"apiKeyAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"ref_id","items"},
     *             @OA\Property(property="ref_id", type="string", example="SHOP-987"),
     *             @OA\Property(property="shipping_label", type="string", example="https://drive.google.com/file/d/ABC/view"),
     *             @OA\Property(property="address", type="object", description="Required nếu KHÔNG có shipping_label"),
     *             @OA\Property(
     *                 property="items", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="product_variant_id", type="integer", example=42),
     *                     @OA\Property(property="accessory_ids", type="array", @OA\Items(type="integer"), example={18}),
     *                     @OA\Property(property="quantity", type="integer", default=1, example=1)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Order created"),
     *     @OA\Response(response=401, description="Invalid or missing X-API-KEY"),
     *     @OA\Response(response=409, description="Duplicate ref_id")
     * )
     */
    public function fulfillByApiKey(Request $request)
    {
        $user = $request->get('api_user');
        $byStamp = $request->boolean('ship_by_stamp');
        $hasLabel = !$byStamp && !empty($request->shipping_label);

        $request->validate([
            'ref_id' => 'nullable|string',
            'note' => 'nullable|string|max:5000',
            'ship_by_stamp' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.material_id' => 'nullable|exists:materials,id',
            // Legacy single accessory (kept for backward compat — callers may
            // still send it). Multi-accessory uses accessory_ids[] below.
            'items.*.accessory_item_id' => 'nullable|exists:accessory_prices,id',
            'items.*.accessory_ids' => 'nullable|array',
            'items.*.accessory_ids.*' => 'integer|exists:accessory_prices,id',
            'items.*.mockup_front' => 'nullable|string',
            'items.*.mockup_back' => 'nullable|string',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.order_type' => 'nullable|integer|in:0,1',
            'items.*.metas' => 'nullable|array',
            'items.*.metas.*.key' => 'required_with:items.*.metas|string',
            'items.*.metas.*.value' => 'nullable|string',
            'shipping_label' => 'nullable|string',
            'address' => $hasLabel ? 'nullable|array' : 'required|array',
            'address.first_name' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.last_name' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.address_1' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.address_2' => 'nullable|string',
            'address.city' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.state' => 'nullable|string',
            'address.zipcode' => $hasLabel ? 'nullable|string' : 'required|string',
            'address.country' => $hasLabel ? 'nullable|string' : 'required|string',
        ]);

        if ($byStamp) {
            $stampMax = Order::stampConfig()['max_items'];
            $stampQty = collect($request->items)->sum(fn ($i) => max(1, (int) ($i['quantity'] ?? 1)));
            if ($stampQty > $stampMax) {
                return response()->json([
                    'message' => 'Ship by stamp tối đa ' . $stampMax . ' items. Đơn này ' . $stampQty . ' items — dùng label thường.',
                ], 422);
            }
        }

        $rawRefId = $request->ref_id ? trim($request->ref_id) : null;
        if ($rawRefId) {
            $exists = Order::where('user_id', $user->id)->where('ref_id', $rawRefId)->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'Duplicate ref_id',
                    'duplicates' => [(string) $rawRefId],
                ], 409);
            }
        }

        return DB::transaction(function () use ($request, $user, $hasLabel, $byStamp, $rawRefId) {
            $firstVariantId = (int) ($request->items[0]['product_variant_id'] ?? 0) ?: null;
            $order = Order::create([
                'user_id' => $user->id,
                'system_id' => $this->generateSystemId(), // temporary, updated after we have order.id
                'ref_id' => $rawRefId,
                'note' => $request->input('note'),
                'status' => Order::STATUS_NEW_ORDER,
                'ship_type' => $byStamp ? Order::SHIP_STAMP : ($hasLabel ? Order::SHIP_TIKTOK : Order::SHIP_SELLER),
                'shipping_label' => $hasLabel ? $request->shipping_label : null,
                'shipping_cost' => 0,
            ]);
            $order->update(['system_id' => $this->buildSystemIdForOrder($order->id, $firstVariantId)]);

            $userTierId = $user->tier_id;
            $printCost = 0;
            $totalQty = 0;
            $refVariantId = null;
            foreach ($request->items as $itemData) {
                $price = $this->computeItemPrice($itemData['metas'] ?? null, $itemData['product_variant_id'], $userTierId);
                $quantity = max(1, (int)($itemData['quantity'] ?? 1));

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $itemData['product_variant_id'],
                    'material_id' => $this->resolveMaterialId($itemData['material_id'] ?? null, $itemData['product_variant_id']),
                    'accessory_item_id' => null,
                    'mockup_front' => $itemData['mockup_front'] ?? null,
                    'mockup_back' => $itemData['mockup_back'] ?? null,
                    'order_type' => $itemData['order_type'] ?? 0,
                    'order_type_label' => $this->productOrderType((int) $itemData['product_variant_id']),
                    'price' => $price,
                    'quantity' => $quantity,
                ]);
                $accessoryIds = $this->collectAccessoryIds($itemData);
                $accessoryUnitPrice = $this->syncItemAccessories($item, $accessoryIds, $userTierId);
                $printCost += ($price + $accessoryUnitPrice) * $quantity;
                $totalQty += $quantity;
                if ($refVariantId === null) $refVariantId = $itemData['product_variant_id'];

                if (!empty($itemData['metas'])) {
                    foreach ($itemData['metas'] as $meta) {
                        OrderItemMeta::create([
                            'order_item_id' => $item->id,
                            'key' => $meta['key'],
                            'value' => $meta['value'] ?? null,
                        ]);
                    }
                }
            }

            if (!$hasLabel && $request->address) {
                Address::create(array_merge($request->address, ['order_id' => $order->id]));
            }

            $shippingCost = $byStamp
                ? Order::stampShippingCost($totalQty)
                : $this->computeOrderShipping($refVariantId, $userTierId, $hasLabel, $totalQty);

            $order->update([
                'print_cost' => $printCost,
                'shipping_cost' => $shippingCost,
                'total_cost' => $printCost + $shippingCost,
            ]);

            return response()->json([
                'message' => 'Order created via API key',
                'order' => $order->fresh()->load(['items.metas', 'items.productVariant', 'items.material', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'items.accessoryPrices.accessory', 'items.accessoryPrices.tier', 'address']),
            ], 201);
        });
    }

    /**
     * Resolve an order item's material: keep the explicitly provided id, else
     * fall back to the product's default material (materials.is_default=1) for
     * the variant's product. Returns null when the product has no default.
     */
    // ── Resend / re-ship ───────────────────────────────────────────────

    /** Current seller's monthly resend quota usage + the config for the modal. */
    public function resendQuota(Request $request)
    {
        $user = $request->user();
        $cfg = Order::resendConfig();
        $freeQuota = $user->resend_free_quota ?? ($cfg['quota']['default_free'] ?? 20);
        $used = $this->resendQuotaUsed($user);

        return response()->json([
            'free_quota'             => (int) $freeQuota,
            'used'                   => $used,
            'free_remaining'         => max(0, $freeQuota - $used),
            'after_free_support_pct' => (float) ($cfg['quota']['after_free_support_pct'] ?? 50),
            'methods'                => $cfg['methods'],
            'reasons'                => $cfg['reasons'],
        ]);
    }

    /** Price a resend without creating it (modal preview). */
    public function resendPreview(Request $request, Order $order)
    {
        $user = $request->user();
        if ($user->hasRole('seller') && $order->user_id !== $user->id) {
            abort(403);
        }
        $cfg = Order::resendConfig();
        $data = $request->validate([
            'method' => 'required|in:' . implode(',', array_column($cfg['methods'], 'key')),
            'reason' => 'required|in:' . implode(',', array_column($cfg['reasons'], 'key')),
        ]);
        $order->load('items.metas', 'items.accessoryPrices', 'items.accessoryPrice');
        return response()->json($this->computeResendPricing($order, $data['method'], $data['reason'], $order->user));
    }

    /**
     * Create a resend order from an existing one: copies its items + source
     * designs + address, ships via the chosen method (label ⇒ seller_ship with
     * tracking, stamp ⇒ no tracking), and prices it per the settings-driven
     * support rules. Created unpaid — the seller pays it via the normal flow.
     */
    public function storeResend(Request $request, Order $order)
    {
        $user = $request->user();
        if ($user->hasRole('seller') && $order->user_id !== $user->id) {
            abort(403);
        }

        $cfg = Order::resendConfig();
        $methodKeys = array_column($cfg['methods'], 'key');
        $reasonKeys = array_column($cfg['reasons'], 'key');
        $data = $request->validate([
            'method'             => 'required|in:' . implode(',', $methodKeys),
            'reason'             => 'required|in:' . implode(',', $reasonKeys),
            'note'               => 'nullable|string',
            // Optional shipping address override (resend often goes to a
            // corrected address). Falls back to the original order's address.
            'address'            => 'nullable|array',
            'address.first_name' => 'nullable|string',
            'address.last_name'  => 'nullable|string',
            'address.address_1'  => 'nullable|string',
            'address.address_2'  => 'nullable|string',
            'address.city'       => 'nullable|string',
            'address.state'      => 'nullable|string',
            'address.zipcode'    => 'nullable|string',
            'address.country'    => 'nullable|string',
            'address.phone'      => 'nullable|string',
            'address.email'      => 'nullable|string',
        ]);

        $order->load(['items.metas', 'items.accessoryPrices', 'items.accessoryPrice', 'address']);
        $owner = $order->user;   // resend belongs to the original order's seller
        $pricing = $this->computeResendPricing($order, $data['method'], $data['reason'], $owner);

        $addressOverride = $request->input('address');

        $response = DB::transaction(function () use ($order, $owner, $data, $pricing, $addressOverride) {
            $firstVariantId = (int) ($order->items->first()->product_variant_id ?? 0) ?: null;
            $resend = Order::create([
                'user_id'            => $owner->id,
                'resend_of_order_id' => $order->id,
                'resend_method'      => $data['method'],
                'resend_reason'      => $data['reason'],
                'base_support'       => $pricing['support'],
                'system_id'          => $this->generateSystemId(),
                'ref_id'             => $order->ref_id,
                'note'               => $data['note'] ?? ('Resend of ' . $order->system_id),
                'status'             => Order::STATUS_NEW_ORDER,
                'ship_type'          => $pricing['ship_type'],
                'shipping_label'     => null,
                'print_cost'         => $pricing['base'],
                'shipping_cost'      => $pricing['shipping'],
                'total_cost'         => $pricing['total'],
            ]);
            $resend->update(['system_id' => $this->buildSystemIdForOrder($resend->id, $firstVariantId)]);

            foreach ($order->items as $src) {
                $item = OrderItem::create([
                    'order_id'           => $resend->id,
                    'product_variant_id' => $src->product_variant_id,
                    'material_id'        => $src->material_id,
                    'mockup_front'       => $src->mockup_front,
                    'mockup_back'        => $src->mockup_back,
                    'order_type'         => $src->order_type,
                    'order_type_label'   => $src->order_type_label ?? $this->productOrderType((int) $src->product_variant_id),
                    'price'              => $src->price,
                    'quantity'           => $src->quantity,
                ]);
                foreach ($src->metas as $m) {
                    // Copy SOURCE designs only — _qr is rebuilt by the convert cron.
                    if (preg_match('/_qr(_\d+)?$/', $m->key)) continue;
                    OrderItemMeta::create(['order_item_id' => $item->id, 'key' => $m->key, 'value' => $m->value]);
                }
                $accIds = $src->accessoryPrices->pluck('id')->all();
                if (!empty($accIds)) $item->accessoryPrices()->sync($accIds);
            }

            // Address: use the override when provided, else copy the original's.
            $addrData = array_filter($addressOverride ?? [], fn ($v) => $v !== null && $v !== '');
            if (!empty($addrData)) {
                Address::create(array_merge(
                    $order->address ? $order->address->only(['first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'zipcode', 'country', 'phone', 'email']) : [],
                    $addrData,
                    ['order_id' => $resend->id]
                ));
            } elseif ($order->address) {
                $addr = $order->address->replicate();
                $addr->order_id = $resend->id;
                $addr->save();
            }

            return response()->json([
                'message' => 'Resend order created',
                'order'   => $resend->fresh()->load(['items.metas', 'items.productVariant', 'address', 'resendOf:id,system_id']),
                'pricing' => $pricing,
            ], 201);
        });

        // The resend copies the original's ref_id, so the seller now has ≥2
        // orders with that ref_id — refresh the duplicate snapshot so the DUP
        // flag shows on both (other write paths do the same).
        $this->refreshDuplicateRefIds();

        return $response;
    }

    /** How many quota-bearing (third-party-style) resends this seller made this VN month. */
    private function resendQuotaUsed($user): int
    {
        $quotaReasons = collect(Order::resendConfig()['reasons'])
            ->where('base_support', 'quota')->pluck('key')->all();
        if (empty($quotaReasons)) return 0;

        $vnNow = now('Asia/Ho_Chi_Minh');
        return Order::where('user_id', $user->id)
            ->whereIn('resend_reason', $quotaReasons)
            ->whereRaw("DATE_FORMAT(DATE_ADD(created_at, INTERVAL 7 HOUR), '%Y-%m') = ?", [$vnNow->format('Y-m')])
            ->count();
    }

    /**
     * Full resend pricing with a per-component breakdown. Each item contributes
     * base_cost + face fee (2nd_fee × extra designs) + accessory cost (× qty).
     * The workshop support (quota/full/none) is applied ONLY to the fee
     * components the reason is configured to cover (reason.covers, default
     * ['base_cost']); the seller always pays shipping + uncovered components.
     */
    private function computeResendPricing(Order $order, string $methodKey, string $reasonKey, $owner): array
    {
        $cfg = Order::resendConfig();
        $method = collect($cfg['methods'])->firstWhere('key', $methodKey) ?? [];
        $reason = collect($cfg['reasons'])->firstWhere('key', $reasonKey) ?? [];
        $tierId = $owner->tier_id;

        // Aggregate the three fee components across all items.
        $baseCost = 0.0; $faceFee = 0.0; $accFee = 0.0;
        foreach ($order->items as $it) {
            $qty = max(1, (int) $it->quantity);
            $baseCost += $this->getVariantPriceByKey($it->product_variant_id, $tierId, 'base_cost') * $qty;

            // Face fee: 2nd_fee × (distinct design faces − 1).
            $designKeys = ['front', 'back', 'left', 'right', 'neck', 'special'];
            $faces = $it->relationLoaded('metas')
                ? $it->metas->filter(fn ($m) => in_array($m->key, $designKeys, true) && trim((string) $m->value) !== '')->count()
                : 1;
            if ($faces > 1) {
                $faceFee += ($faces - 1) * $this->getVariantPriceByKey($it->product_variant_id, $tierId, '2nd_fee') * $qty;
            }

            // Accessory cost (multi-pivot, else legacy single).
            $accUnit = $it->relationLoaded('accessoryPrices') && $it->accessoryPrices->isNotEmpty()
                ? (float) $it->accessoryPrices->sum('price')
                : (float) ($it->accessoryPrice->price ?? 0);
            $accFee += $accUnit * $qty;
        }

        $shipping = (float) ($method['price'] ?? 0);
        // Per-seller override of the support % for this reason (if any) wins.
        $override = $owner->resend_support_override[$reasonKey] ?? null;
        $supportPct = is_array($override) ? $override : ($reason['support'] ?? []);  // per-component % (0–100)
        $quotaBased = !empty($reason['quota_based']);

        // Past the monthly free quota, scale every component's support down.
        $afterFactor = 1.0;
        if ($quotaBased) {
            $freeQuota = $owner->resend_free_quota ?? ($cfg['quota']['default_free'] ?? 20);
            if ($this->resendQuotaUsed($owner) >= $freeQuota) {
                $afterFactor = ((float) ($cfg['quota']['after_free_support_pct'] ?? 50)) / 100;
            }
        }

        $rawComps = [
            ['key' => 'base_cost', 'label' => 'Base cost',    'amount' => $baseCost],
            ['key' => '2nd_fee',   'label' => 'Phí mặt thêm', 'amount' => $faceFee],
            ['key' => 'accessory', 'label' => 'Accessory',    'amount' => $accFee],
            ['key' => 'shipping',  'label' => 'Shipping',      'amount' => $shipping],
        ];
        $comps = [];
        $support = 0.0;
        foreach ($rawComps as $c) {
            // Shipping is always seller-paid; others use the reason's % × afterFactor.
            $pct = $c['key'] === 'shipping' ? 0.0 : ((float) ($supportPct[$c['key']] ?? 0)) * $afterFactor;
            $supAmt = round($c['amount'] * $pct / 100, 2);
            $support += $supAmt;
            $comps[] = [
                'key'            => $c['key'],
                'label'          => $c['label'],
                'amount'         => round($c['amount'], 2),
                'support_pct'    => round($pct, 0),
                'support_amount' => $supAmt,
                'charged'        => round($c['amount'] - $supAmt, 2),
                'supported'      => $supAmt > 0,
            ];
        }

        $subtotal = $baseCost + $faceFee + $accFee + $shipping;
        $support = round($support, 2);

        return [
            'ship_type'  => $method['ship_type'] ?? Order::SHIP_SELLER,
            'shipping'   => round($shipping, 2),
            'base'       => round($baseCost + $faceFee + $accFee, 2),
            'support'    => $support,
            'components' => $comps,
            'total'      => round($subtotal - $support, 2),
        ];
    }

    private function resolveMaterialId(?int $providedMaterialId, $variantId): ?int
    {
        if (!empty($providedMaterialId)) return (int) $providedMaterialId;
        if (empty($variantId)) return null;
        return \App\Models\Material::where('is_default', true)
            ->whereIn('product_id', \App\Models\ProductVariant::where('id', $variantId)->select('product_id'))
            ->orderBy('id')
            ->value('id');
    }

    private function getVariantPrice(int $variantId, ?int $tierId): float
    {
        return $this->getVariantPriceByKey($variantId, $tierId, 'base_cost');
    }

    /**
     * The order_type label of the product behind a variant (products.order_type).
     * Snapshotted onto order_items.order_type_label at create/update time so the
     * item's "Type" reflects the product chosen, even if the product changes
     * later. Cached per request to avoid repeat lookups in bulk creates.
     */
    private array $orderTypeCache = [];
    private function productOrderType(?int $variantId): ?string
    {
        if (!$variantId) return null;
        if (array_key_exists($variantId, $this->orderTypeCache)) {
            return $this->orderTypeCache[$variantId];
        }
        $label = \App\Models\ProductVariant::where('id', $variantId)
            ->with('product:id,order_type')
            ->first()?->product?->order_type;
        return $this->orderTypeCache[$variantId] = $label;
    }

    /**
     * Per-item unit price = base_cost + 2nd_fee × (faceCount − 1).
     *
     * `faceCount` counts the distinct design metas present (non-empty value)
     * among front/back/left/right/neck/special. Single-face items pay just
     * base_cost; 2-sided items pay base + 2nd_fee; 3-sided items pay base +
     * 2 × 2nd_fee, and so on.
     *
     * Used by store(), importCsv() and fulfillByApiKey() so all three create
     * paths price 2-sided items identically.
     */
    private function computeItemPrice(?iterable $metas, int $variantId, ?int $tierId): float
    {
        $price = $this->getVariantPriceByKey($variantId, $tierId, 'base_cost');

        $designKeys = ['front', 'back', 'left', 'right', 'neck', 'special'];
        $present = [];
        foreach ($metas ?? [] as $m) {
            $k = is_array($m) ? ($m['key'] ?? null) : ($m->key ?? null);
            $v = is_array($m) ? ($m['value'] ?? null) : ($m->value ?? null);
            if ($k && in_array($k, $designKeys, true) && $v !== null && trim((string) $v) !== '') {
                $present[$k] = true;
            }
        }
        $faceCount = count($present);

        if ($faceCount > 1) {
            $secondFee = $this->getVariantPriceByKey($variantId, $tierId, '2nd_fee');
            $price += ($faceCount - 1) * $secondFee;
        }

        return round($price, 2);
    }

    private function generateSystemId(): string
    {
        do {
            $sid = 'BS-' . strtoupper(Str::random(8));
        } while (Order::where('system_id', $sid)->exists());
        return $sid;
    }

    /**
     * Build the order's final system_id. If the first item points at a product
     * with a non-empty line_id (e.g. "GC"), we use "<line_id><order.id>"
     * (e.g. GC123). Always unique because order.id is the primary key, but we
     * still defend against cross-line_id collisions (e.g. "GC1"+23 vs "GC"+123)
     * by appending an incrementing suffix; falls back to a random BS-XXXX
     * after 50 attempts.
     */
    private function buildSystemIdForOrder(int $orderId, ?int $firstVariantId): string
    {
        if ($firstVariantId) {
            $variant = ProductVariant::with('product:id,line_id')->find($firstVariantId);
            $lineId = $variant?->product?->line_id;
            if ($lineId) {
                $base = $lineId . $orderId;
                $sid = $base;
                $suffix = 1;
                while (Order::where('system_id', $sid)->where('id', '!=', $orderId)->exists()) {
                    $sid = $base . '-' . $suffix;
                    if (++$suffix > 50) {
                        return $this->generateSystemId();
                    }
                }
                return $sid;
            }
        }
        return $this->generateSystemId();
    }

    /**
     * Compute total order shipping cost based on ship mode:
     *   tiktok_ship (hasLabel)  → label_fee (one-shot, ignores qty)
     *   seller_ship (no label)  → shipping_cost + (totalQty - 1) * addition_fee
     * All prices are looked up against the order's reference variant + user tier.
     */
    private function computeOrderShipping(?int $refVariantId, ?int $tierId, bool $hasLabel, int $totalQty): float
    {
        if (!$refVariantId) return 0;
        if ($hasLabel) {
            return $this->getVariantPriceByKey($refVariantId, $tierId, 'label_fee');
        }
        $base = $this->getVariantPriceByKey($refVariantId, $tierId, 'shipping_cost');
        $addition = $this->getVariantPriceByKey($refVariantId, $tierId, 'addition_fee');
        return $base + max(0, $totalQty - 1) * $addition;
    }

    private function getVariantPriceByKey(int $variantId, ?int $tierId, string $key): float
    {
        $query = ProductVariantPrice::where('product_variant_id', $variantId)
            ->where('key', $key);

        if ($tierId) {
            $price = (clone $query)->where('tier_id', $tierId)->first();
            if ($price) return (float)$price->price;
        }

        $price = $query->first();
        return $price ? (float)$price->price : 0;
    }

    private function getVariantCosts(int $variantId, ?int $tierId): array
    {
        $costs = ['base_cost' => 0, 'label_fee' => 0, 'shipping_cost' => 0];

        foreach (ProductVariantPrice::KEYS as $key) {
            $query = ProductVariantPrice::where('product_variant_id', $variantId)
                ->where('key', $key);

            if ($tierId) {
                $price = (clone $query)->where('tier_id', $tierId)->first();
                if (!$price) $price = $query->first();
            } else {
                $price = $query->first();
            }

            $costs[$key] = $price ? (float)$price->price : 0;
        }

        return $costs;
    }

    /**
     * Recompute ONLY the order's shipping against the current price tables,
     * keeping print_cost exactly as stored. Used by the shipping-fix flow:
     * a mis-entered shipping price on a variant is corrected in the price
     * table, then affected orders are re-totalled without touching item /
     * add-on prices (which may legitimately differ or have been edited).
     *
     * total_cost is rebuilt as (stored print_cost + corrected shipping) so it
     * self-heals any prior drift while never re-pricing the print side.
     * Returns ['print_cost','shipping_cost','total_cost']. Persists nothing.
     */
    private function recomputeOrderShipping(Order $order): array
    {
        $order->loadMissing('items', 'user');
        $tierId = $order->user?->tier_id;

        $totalQty = 0;
        foreach ($order->items as $it) {
            $totalQty += max(1, (int) ($it->quantity ?? 1));
        }

        $hasLabel       = !empty($order->shipping_label);
        $firstVariantId = (int) ($order->items->first()?->product_variant_id ?? 0);
        $newShipping    = $firstVariantId
            ? round((float) $this->computeOrderShipping($firstVariantId, $tierId, $hasLabel, $totalQty), 2)
            : round((float) $order->shipping_cost, 2);

        $print    = round((float) $order->print_cost, 2);
        $newTotal = round($print + $newShipping, 2);

        return [
            'print_cost'    => $print,
            'shipping_cost' => $newShipping,
            'total_cost'    => $newTotal,
        ];
    }

    /**
     * POST /api/orders/recalc-shipping/preview — admin/support only.
     *
     * For the explicitly-selected order_ids, recompute shipping only (keeping
     * print_cost) and report where it drifted from what the order stored. When
     * the order was already paid and the correct total is now lower, reports the
     * refund that would go back to the owner's wallet. Nothing is written.
     *
     * Per-order selection (not filter-wide) is deliberate: the caller picks
     * exactly the orders touched by the mis-priced variant, avoiding a mass
     * mistake. Body: { order_ids: int[] }.
     */
    public function recalcShippingPreview(Request $request)
    {
        $caller = $request->user();
        if (!($caller?->role && in_array($caller->role->slug, ['admin', 'support'], true))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $orders = Order::with(['items', 'user'])->whereIn('id', $request->order_ids)->get();

        $rows        = [];
        $totalRefund = 0.0;
        foreach ($orders as $order) {
            $new      = $this->recomputeOrderShipping($order);
            $oldShip  = round((float) $order->shipping_cost, 2);
            $oldTotal = round((float) $order->total_cost, 2);
            $newShip  = $new['shipping_cost'];
            $newTotal = $new['total_cost'];
            $paid     = round((float) $order->paid_cost, 2);
            $refund   = max(0, round($paid - $newTotal, 2));

            // Only surface orders whose shipping/total actually changed.
            if (abs($newShip - $oldShip) < 0.005 && abs($newTotal - $oldTotal) < 0.005) {
                continue;
            }

            $totalRefund += $refund;
            $rows[] = [
                'id'            => $order->id,
                'system_id'     => $order->system_id,
                'ref_id'        => $order->ref_id,
                'user_id'       => $order->user_id,
                'user_name'     => $order->user?->name,
                'status'        => (int) $order->status,
                'old_shipping'  => $oldShip,
                'new_shipping'  => $newShip,
                'diff_shipping' => round($newShip - $oldShip, 2),
                'old_total'     => $oldTotal,
                'new_total'     => $newTotal,
                'paid_cost'     => $paid,
                'is_paid'       => $paid > 0.005,
                'refund'        => $refund,
            ];
        }

        return response()->json([
            'requested'    => count($request->order_ids),
            'changed'      => count($rows),
            'total_refund' => round($totalRefund, 2),
            'orders'       => $rows,
        ]);
    }

    /**
     * POST /api/orders/recalc-shipping/apply — admin/support only.
     *
     * Applies the shipping-only recalc for the given order_ids. For each order
     * whose shipping/total drifted:
     *   1. Persist the corrected shipping_cost + total_cost (print_cost kept).
     *   2. If it was paid and now overpaid (paid_cost > new total), refund the
     *      difference to the owner's wallet and write a `refund` WalletTransaction
     *      ("Refund chênh lệch shipping"), then set paid_cost = new total.
     *   3. Underpaid orders are only re-totalled — no extra charge is taken.
     *
     * One DB::transaction so a failure rolls the whole batch back.
     * Body: { order_ids: int[] }.
     */
    public function recalcShippingApply(Request $request)
    {
        $caller = $request->user();
        if (!($caller?->role && in_array($caller->role->slug, ['admin', 'support'], true))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $orders = Order::with(['items', 'user'])->whereIn('id', $request->order_ids)->get();

        $result = DB::transaction(function () use ($orders) {
            $updated      = 0;
            $refunded     = 0;
            $totalRefund  = 0.0;
            $walletCache  = []; // user_id => locked User (running balance across the batch)
            $details      = [];

            foreach ($orders as $order) {
                $new      = $this->recomputeOrderShipping($order);
                $newShip  = $new['shipping_cost'];
                $newTotal = $new['total_cost'];
                $oldShip  = round((float) $order->shipping_cost, 2);
                $oldTotal = round((float) $order->total_cost, 2);
                $paid     = round((float) $order->paid_cost, 2);

                // Nothing drifted → leave the order untouched.
                if (abs($newShip - $oldShip) < 0.005 && abs($newTotal - $oldTotal) < 0.005) {
                    continue;
                }

                $refund  = max(0, round($paid - $newTotal, 2));
                $updates = [
                    'shipping_cost' => $newShip,
                    'total_cost'    => $newTotal,
                ];

                if ($refund > 0 && $order->user) {
                    $owner = $walletCache[$order->user_id]
                        ??= \App\Models\User::lockForUpdate()->find($order->user_id);
                    $balanceBefore = (float) $owner->wallet;
                    $owner->increment('wallet', $refund);
                    $owner->refresh();

                    $updates['paid_cost'] = $newTotal;

                    WalletTransaction::create([
                        'user_id'         => $owner->id,
                        'order_id'        => $order->id,
                        'order_system_id' => $order->system_id,
                        'type'            => 'refund',
                        'status'          => WalletTransaction::STATUS_APPROVED,
                        'amount'          => $refund,
                        'balance_before'  => $balanceBefore,
                        'balance_after'   => (float) $owner->wallet,
                        'refunded_at'     => now(),
                        'note'            => "Refund chênh lệch shipping đơn #{$order->system_id} ({$oldShip} → {$newShip})",
                    ]);

                    $refunded++;
                    $totalRefund += $refund;
                }

                $order->update($updates);
                $updated++;

                $details[] = [
                    'id'           => $order->id,
                    'system_id'    => $order->system_id,
                    'new_shipping' => $newShip,
                    'new_total'    => $newTotal,
                    'refund'       => $refund,
                ];
            }

            return [$updated, $refunded, $totalRefund, $details];
        });

        [$updated, $refunded, $totalRefund, $details] = $result;

        return response()->json([
            'message'        => "Đã tính lại {$updated} đơn, refund {$refunded} đơn tổng \$" . number_format($totalRefund, 2),
            'updated_count'  => $updated,
            'refunded_count' => $refunded,
            'total_refund'   => round($totalRefund, 2),
            'details'        => $details,
        ]);
    }

    /**
     * Shared list filters for GET /orders, /orders/export, /orders/invoice-data,
     * and GET /fulfill. Callers must apply seller/admin user scoping first.
     */
    private function applyOrderListFilters(Builder $query, Request $request): void
    {
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('ship_type')) {
            $query->where('ship_type', $request->ship_type);
        }

        if ($request->filled('ref_id')) {
            $query->where('ref_id', 'like', '%' . $request->ref_id . '%');
        }

        if ($request->filled('ref_ids')) {
            $list = preg_split('/[\s,]+/', (string) $request->ref_ids, -1, PREG_SPLIT_NO_EMPTY);
            $list = array_values(array_unique(array_map('trim', $list)));
            if (!empty($list)) {
                $query->whereIn('ref_id', $list);
            }
        }

        if ($request->has('system_id')) {
            $query->where('system_id', 'like', '%' . $request->system_id . '%');
        }

        if ($request->has('tracking_id')) {
            $query->where('tracking_id', 'like', '%' . $request->tracking_id . '%');
        }

        if ($request->filled('tracking_status')) {
            $ts = $request->tracking_status;
            if ($ts === 'none') {
                $query->whereDoesntHave('tracking');
            } else {
                $query->whereHas('tracking', fn ($q) => $q->where('status', $ts));
            }
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('paid')) {
            if ($request->paid === 'paid') {
                $query->whereColumn('paid_cost', '>=', 'total_cost');
            } elseif ($request->paid === 'unpaid') {
                $query->whereColumn('paid_cost', '<', 'total_cost');
            }
        }

        if ($request->filled('order_ids')) {
            $ids = is_array($request->order_ids)
                ? $request->order_ids
                : preg_split('/[\s,]+/', (string) $request->order_ids, -1, PREG_SPLIT_NO_EMPTY);
            $query->whereIn('id', array_map('intval', $ids));
        }

        $this->applyProductFilters($query, $request);
    }

    /**
     * Product-related filters — order matches if at least one order_item satisfies
     * the condition (OR across items, AND when multiple product params are set).
     */
    private function applyProductFilters(Builder $query, Request $request): void
    {
        $productIds = $this->parseIdList($request, 'product_ids');
        if ($request->filled('product_id')) {
            $productIds[] = (int) $request->product_id;
        }
        $productIds = array_values(array_unique(array_filter($productIds)));
        if (!empty($productIds)) {
            $query->whereHas('items.productVariant', fn ($q) => $q->whereIn('product_id', $productIds));
        }

        if ($request->filled('product_variant_id')) {
            $query->whereHas('items', fn ($q) =>
                $q->where('product_variant_id', (int) $request->product_variant_id));
        }

        if ($request->filled('line_id')) {
            $query->whereHas('items.productVariant.product', fn ($q) =>
                $q->where('line_id', $request->line_id));
        }

        if ($request->filled('sku')) {
            $query->whereHas('items.productVariant', fn ($q) =>
                $q->where('sku', $request->sku));
        }

        foreach (['color', 'size', 'paper_type'] as $field) {
            if ($request->filled($field)) {
                $value = $request->input($field);
                $query->whereHas('items.productVariant', fn ($q) => $q->where($field, $value));
            }
        }

        if ($request->filled('material_id')) {
            $query->whereHas('items', fn ($q) =>
                $q->where('material_id', (int) $request->material_id));
        }

        if ($request->filled('accessory_id')) {
            $accessoryId = (int) $request->accessory_id;
            $query->whereHas('items', function ($q) use ($accessoryId) {
                $q->where(function ($w) use ($accessoryId) {
                    $w->whereHas('accessoryPrice', fn ($ap) => $ap->where('accessory_id', $accessoryId))
                      ->orWhereHas('accessoryPrices', fn ($ap) => $ap->where('accessory_id', $accessoryId));
                });
            });
        }

        if ($request->filled('accessory_code')) {
            $code = strtoupper(trim($request->accessory_code));
            $query->whereHas('items', function ($q) use ($code) {
                $q->where(function ($w) use ($code) {
                    $w->whereHas('accessoryPrice', fn ($ap) => $ap->where('accessory_code', $code))
                      ->orWhereHas('accessoryPrices', fn ($ap) => $ap->where('accessory_code', $code));
                });
            });
        }
    }

    /** @return int[] */
    private function parseIdList(Request $request, string $key): array
    {
        if (!$request->filled($key)) {
            return [];
        }
        $raw = $request->input($key);
        $parts = is_array($raw)
            ? $raw
            : preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map('intval', $parts))));
    }
}
