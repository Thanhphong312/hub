<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ConversionController extends Controller
{
    /** Source meta keys we want to convert. */
    private const SOURCE_KEYS = ['front', 'back', 'left', 'right', 'neck', 'special'];

    /** Regex matching every produced _qr meta key, e.g. front_qr, front_qr_1, back_qr_3. */
    private const QR_KEY_REGEX_SQL = '_qr(_[0-9]+)?$';

    /**
     * Build a list of _qr metas that still need to be generated for the
     * authenticated user's orders.
     *
     * For an item with source keys S = [front, back, ...] in canonical order
     * and quantity Q, total = |S| * Q. Each source key gets Q output metas
     * named "<source>_qr_<copy>" with copy=1..Q, and a global running index
     * 1..total (front copies first, then back, etc.).
     */
    public function pending(Request $request)
    {
        $user = $request->user();
        if (!$user->convert) {
            return response()->json(['message' => 'Convert mode is not enabled for this account'], 403);
        }

        $isStaff = $user->role && in_array($user->role->slug, ['admin', 'support']);

        // Iterate at the order level so we can compute the accessory summary
        // (combining all items of the same order) once per order.
        $query = Order::with([
                'items.metas',
                'items.accessoryPrice',
                'items.productVariant.product:id,line_id',
            ])
            ->whereHas('items.metas', fn ($q) => $q->whereIn('key', self::SOURCE_KEYS))
            ->orderBy('id', 'asc');

        // Sellers can only convert their own orders. Admin/support can convert
        // anyone's so they can recover stuck conversions for other accounts.
        if (!$isStaff) {
            $query->where('user_id', $user->id);
        }

        $orders = $query->get();

        $result = [];
        foreach ($orders as $order) {
            $accessorySummary = $this->computeAccessorySummary($order);
            foreach ($order->items as $item) {
                $existingByKey = $item->metas->keyBy('key');

                $sourceKeysWithValue = [];
                foreach (self::SOURCE_KEYS as $sk) {
                    $m = $existingByKey->get($sk);
                    if ($m && !empty($m->value)) {
                        $sourceKeysWithValue[] = $sk;
                    }
                }
                if (empty($sourceKeysWithValue)) continue;

                $qty = max(1, (int) ($item->quantity ?? 1));
                $total = count($sourceKeysWithValue) * $qty;

                // Detect greeting-card / card-line products. Accepts any of:
                //   - line_id == "GC" or starts with "GC" (Greeting Card)
                //   - line_id ends with "_C"  (Pressify convention: PS_C, HC_C, …)
                //   - line_id is 2 chars ending in C (LC, HC, …)
                //   - product name contains "greeting" or "card"
                //     (covers the existing "Greating Card" typo too).
                $product = $item->productVariant?->product;
                $lineId = strtoupper((string) ($product->line_id ?? ''));
                $productName = strtolower((string) ($product->name ?? ''));
                $isGcProduct = $lineId === 'GC'
                    || str_starts_with($lineId, 'GC')
                    || str_ends_with($lineId, '_C')
                    || (strlen($lineId) === 2 && str_ends_with($lineId, 'C'))
                    || str_contains($productName, 'greeting')
                    || str_contains($productName, 'card');

                $globalIdx = 0;
                $pending = [];
                foreach ($sourceKeysWithValue as $sk) {
                    $sourceMeta = $existingByKey->get($sk);
                    for ($copy = 1; $copy <= $qty; $copy++) {
                        $globalIdx++;
                        $targetKey = "{$sk}_qr_{$copy}";
                        if ($existingByKey->has($targetKey)) continue;
                        $pending[] = [
                            'source_meta_id' => $sourceMeta->id,
                            'source_key' => $sk,
                            'source_value' => $sourceMeta->value,
                            'target_key' => $targetKey,
                            'copy' => $copy,
                            'total_copies' => $qty,
                            'index' => $globalIdx,
                            'total' => $total,
                            // Pre-computed server-side so the client doesn't
                            // need to do string matching itself. Only "back"
                            // gets the flip + no-overlay treatment.
                            'is_greeting_card_back' => $isGcProduct && $sk === 'back',
                        ];
                    }
                }

                if (!empty($pending)) {
                    $result[] = [
                        'order_item_id' => $item->id,
                        'order_id' => $order->id,
                        'system_id' => $order->system_id,
                        'accessory_summary' => $accessorySummary,
                        'quantity' => $qty,
                        'total_metas' => $total,
                        // Surfaced so the desktop composer can apply
                        // line-specific behavior — currently used to skip
                        // the system_id overlay and rotate 180° for the
                        // back face of a greeting card (line_id "GC").
                        'line_id' => $item->productVariant?->product?->line_id,
                        'pending' => $pending,
                    ];
                }
            }
        }

        // Convert-label queue. Orders still in NEW_ORDER status with a
        // shipping_label set but no convert_label yet. The converter produces
        // a separate image (system_id text + Code 128 barcode + accessory
        // summary stamped on the carrier label) and saves the URL to
        // `orders.convert_label` — the original `shipping_label` is kept
        // intact so the carrier source is never lost.
        $labelQuery = Order::query()
            ->where('status', Order::STATUS_NEW_ORDER)
            ->whereNotNull('shipping_label')
            ->where('shipping_label', '!=', '')
            ->where(function ($q) {
                $q->whereNull('convert_label')->orWhere('convert_label', '');
            })
            ->orderBy('id', 'asc');

        if (!$isStaff) {
            $labelQuery->where('user_id', $user->id);
        }

        $convertLabelPending = $labelQuery->get(['id', 'system_id', 'shipping_label'])
            ->map(fn ($o) => [
                'order_id' => $o->id,
                'system_id' => $o->system_id,
                'shipping_label' => $o->shipping_label,
            ])
            ->values();

        // Build accessory summary per pending convert-label order so the
        // composed image can include it alongside the barcode (matches the
        // _qr layout: "{system_id}-{accessory_summary}").
        $orderIds = $convertLabelPending->pluck('order_id')->all();
        $summaries = [];
        if (!empty($orderIds)) {
            $accOrders = Order::with('items.accessoryPrice')
                ->whereIn('id', $orderIds)
                ->get();
            foreach ($accOrders as $o) {
                $summaries[$o->id] = $this->computeAccessorySummary($o);
            }
        }
        $convertLabelPending = $convertLabelPending->map(function ($row) use ($summaries) {
            $row['accessory_summary'] = $summaries[$row['order_id']] ?? '';
            $row['ship_type'] = Order::SHIP_TIKTOK;
            return $row;
        });

        // Stamp orders: no carrier label — we render a convert_label from the
        // customer ADDRESS plus a big "STAMP" marker. Same pending criteria
        // (new_order, no convert_label yet) but keyed off ship_type=stamp.
        $stampQuery = Order::query()
            ->with('address')
            ->where('status', Order::STATUS_NEW_ORDER)
            ->where('ship_type', Order::SHIP_STAMP)
            ->where(function ($q) {
                $q->whereNull('convert_label')->orWhere('convert_label', '');
            })
            ->orderBy('id', 'asc');
        if (!$isStaff) {
            $stampQuery->where('user_id', $user->id);
        }
        $stampPending = $stampQuery->get()->map(function ($o) {
            return [
                'order_id'          => $o->id,
                'system_id'         => $o->system_id,
                'shipping_label'    => null,
                'ship_type'         => Order::SHIP_STAMP,
                'accessory_summary' => $this->computeAccessorySummary($o),
                'address'           => $o->address ? [
                    'first_name' => $o->address->first_name,
                    'last_name'  => $o->address->last_name,
                    'address_1'  => $o->address->address_1,
                    'address_2'  => $o->address->address_2,
                    'city'       => $o->address->city,
                    'state'      => $o->address->state,
                    'zipcode'    => $o->address->zipcode,
                    'country'    => $o->address->country,
                    'phone'      => $o->address->phone,
                    'email'      => $o->address->email,
                ] : null,
            ];
        })->values();

        return response()->json([
            'items' => $result,
            'convert_label_pending' => $convertLabelPending->concat($stampPending)->values(),
        ]);
    }

    /**
     * Per-order accessory summary, e.g. "5P-2W-1N":
     *   quantities are aggregated by letter (first char of style or accessory_code,
     *   "N" when no accessory). Items sharing the same letter are merged so a
     *   long order doesn't produce a runaway "1P-1P-1P-1P-…" string.
     */
    private function computeAccessorySummary($order): string
    {
        $totals = []; // letter => total qty
        foreach ($order->items as $item) {
            $qty = max(1, (int) ($item->quantity ?? 1));
            $letter = 'N';
            if ($item->accessoryPrice) {
                $style = trim((string) ($item->accessoryPrice->style ?? ''));
                $code = trim((string) ($item->accessoryPrice->accessory_code ?? ''));
                if ($style !== '') {
                    $letter = strtoupper(substr($style, 0, 1));
                } elseif ($code !== '') {
                    $letter = strtoupper(substr($code, 0, 1));
                }
            }
            $totals[$letter] = ($totals[$letter] ?? 0) + $qty;
        }

        $parts = [];
        foreach ($totals as $letter => $qty) {
            $parts[] = "{$qty}{$letter}";
        }
        return implode('-', $parts);
    }

    /**
     * Receive an already-composed image from the desktop app, upload it to B2
     * and persist (or update) the OrderItemMeta with the requested target_key.
     */
    public function uploadQrMeta(Request $request, OrderItem $item)
    {
        $user = $request->user();
        if (!$user->convert) {
            return response()->json(['message' => 'Convert mode is not enabled for this account'], 403);
        }
        $isStaff = $user->role && in_array($user->role->slug, ['admin', 'support']);
        if (!$isStaff && $item->order->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'key' => ['required', 'string', 'regex:/^(front|back|left|right|neck|special)_qr(_\d+)?$/'],
            // ~100 MB ceiling so 3030×2130 / 3130×2130 RGBA PNGs at 300 DPI fit
            // even when the source design has heavy detail. PHP global caps
            // (upload_max_filesize / post_max_size in php.ini) are also at 100M.
            'image' => 'required|file|max:102400',
        ]);

        $newKey = $request->key;
        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $path = "qr/{$item->id}/{$newKey}_" . time() . '.' . $ext;

        Storage::disk('b2')->put($path, file_get_contents($file->getPathname()), 'public');
        $base = rtrim(config('filesystems.disks.b2.url') ?? '', '/');
        $url = $base . '/' . ltrim($path, '/');

        $meta = OrderItemMeta::updateOrCreate(
            ['order_item_id' => $item->id, 'key' => $newKey],
            ['value' => $url]
        );

        return response()->json([
            'message' => 'QR meta saved',
            'meta' => $meta,
        ], 201);
    }

    /**
     * Receive the composed convert-label image (carrier label with system_id
     * text + Code 128 barcode + accessory summary overlaid). Upload to B2 and
     * persist the URL to `orders.convert_label` — the original shipping_label
     * is left untouched so the carrier source can always be retrieved.
     */
    public function uploadConvertLabel(Request $request, Order $order)
    {
        $user = $request->user();
        if (!$user->convert) {
            return response()->json(['message' => 'Convert mode is not enabled for this account'], 403);
        }
        $isStaff = $user->role && in_array($user->role->slug, ['admin', 'support']);
        if (!$isStaff && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // Staff can pass `force=1` to bypass the NEW_ORDER status check —
        // used by the manual "Convert by ID" input on the Convert Label
        // page when operators need to re-overlay an order that already
        // moved past new_order.
        $force = $isStaff && $request->boolean('force');
        if (!$force && $order->status !== Order::STATUS_NEW_ORDER) {
            return response()->json(['message' => 'Order is not in new_order status'], 422);
        }

        $request->validate([
            'image' => 'required|file|max:51200',
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $path = "convert-labels/{$order->id}/system_id_" . time() . '.' . $ext;

        Storage::disk('b2')->put($path, file_get_contents($file->getPathname()), 'public');
        $base = rtrim(config('filesystems.disks.b2.url') ?? '', '/');
        $url = $base . '/' . ltrim($path, '/');

        $order->update(['convert_label' => $url]);

        return response()->json([
            'message' => 'Convert label saved',
            'order' => $order->fresh()->makeVisible('convert_label')
                ->only(['id', 'system_id', 'shipping_label', 'convert_label']),
        ], 201);
    }

    /**
     * Look up a single order by numeric id OR system_id and return the
     * payload needed to render a convert_label (the same shape that
     * `pending()` returns in `convert_label_pending`). Admin/support only.
     * Used by the manual input on the Convert Label page.
     */
    public function lookupLabelTarget(Request $request, string $idOrSystemId)
    {
        $user = $request->user();
        if (!$user->convert) {
            return response()->json(['message' => 'Convert mode is not enabled for this account'], 403);
        }
        $isStaff = $user->role && in_array($user->role->slug, ['admin', 'support']);
        if (!$isStaff) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $needle = trim($idOrSystemId);
        if ($needle === '') {
            return response()->json(['message' => 'Missing id or system_id'], 422);
        }

        $order = ctype_digit($needle)
            ? Order::with('items.accessoryPrice', 'address')->find((int) $needle)
            : Order::with('items.accessoryPrice', 'address')->where('system_id', $needle)->first();

        if (!$order) {
            return response()->json(['message' => "No order found for \"{$needle}\""], 404);
        }

        // Stamp orders convert from the address (no carrier label).
        if ($order->ship_type === Order::SHIP_STAMP) {
            if (!$order->address) {
                return response()->json(['message' => "Stamp order {$order->system_id} has no address"], 422);
            }
            return response()->json([
                'order_id'          => $order->id,
                'system_id'         => $order->system_id,
                'shipping_label'    => null,
                'ship_type'         => Order::SHIP_STAMP,
                'accessory_summary' => $this->computeAccessorySummary($order),
                'status'            => $order->status,
                'address'           => [
                    'first_name' => $order->address->first_name,
                    'last_name'  => $order->address->last_name,
                    'address_1'  => $order->address->address_1,
                    'address_2'  => $order->address->address_2,
                    'city'       => $order->address->city,
                    'state'      => $order->address->state,
                    'zipcode'    => $order->address->zipcode,
                    'country'    => $order->address->country,
                    'phone'      => $order->address->phone,
                    'email'      => $order->address->email,
                ],
            ]);
        }

        if (empty($order->shipping_label)) {
            return response()->json(['message' => "Order {$order->system_id} has no shipping_label"], 422);
        }

        return response()->json([
            'order_id' => $order->id,
            'system_id' => $order->system_id,
            'shipping_label' => $order->shipping_label,
            'ship_type' => $order->ship_type,
            'accessory_summary' => $this->computeAccessorySummary($order),
            'status' => $order->status,
        ]);
    }
}
