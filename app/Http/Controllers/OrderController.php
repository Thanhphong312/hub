<?php

namespace App\Http\Controllers;

use App\Models\AccessoryPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMeta;
use App\Models\Address;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $hideQr = $this->shouldHideQrMetas($request->user());
        $metasRel = $hideQr
            ? ['items.metas' => fn ($q) => $q->where('key', 'not regexp', '_qr(_[0-9]+)?$')]
            : ['items.metas'];

        $query = Order::with(array_merge($metasRel, ['items.productVariant.product', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'address', 'user']));

        // Seller chỉ thấy đơn của mình
        if ($request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user()->id);
        }

        // Filter by user_id (admin/support)
        if ($request->has('user_id') && !$request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by ship_type
        if ($request->has('ship_type')) {
            $query->where('ship_type', $request->ship_type);
        }

        // Search by ref_id
        if ($request->has('ref_id')) {
            $query->where('ref_id', 'like', '%' . $request->ref_id . '%');
        }

        // Search by system_id
        if ($request->has('system_id')) {
            $query->where('system_id', 'like', '%' . $request->system_id . '%');
        }

        // Search by tracking_id
        if ($request->has('tracking_id')) {
            $query->where('tracking_id', 'like', '%' . $request->tracking_id . '%');
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'id');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $orders = $query->paginate($request->get('per_page', 20));
        if ($hideQr) {
            $orders->getCollection()->each(fn ($o) => $o->makeHidden('production'));
        }
        return response()->json($orders);
    }

    public function show(Request $request, Order $order)
    {
        $hideQr = $this->shouldHideQrMetas($request->user());
        $metasRel = $hideQr
            ? ['items.metas' => fn ($q) => $q->where('key', 'not regexp', '_qr(_[0-9]+)?$')]
            : ['items.metas'];

        $order->load(array_merge($metasRel, ['items.productVariant.product', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'address', 'user']));
        if ($hideQr) $order->makeHidden('production');

        return response()->json(['order' => $order]);
    }

    private function shouldHideQrMetas($user): bool
    {
        if (!$user || !$user->role) return true;
        return !in_array($user->role->slug, ['admin', 'support']);
    }

    public function store(Request $request)
    {
        $hasLabel = !empty($request->shipping_label);

        $request->validate([
            'ref_id' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.accessory_item_id' => 'nullable|exists:accessory_prices,id',
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

        return DB::transaction(function () use ($request, $hasLabel) {
            $firstVariantId = (int) ($request->items[0]['product_variant_id'] ?? 0) ?: null;
            $order = Order::create([
                'user_id' => $request->user()->id,
                'system_id' => $this->generateSystemId(), // temporary, updated after we have order.id
                'ref_id' => $request->ref_id ?: null,
                'status' => Order::STATUS_NEW_ORDER,
                'ship_type' => $hasLabel ? 'tiktok_ship' : 'seller_ship',
                'shipping_label' => $hasLabel ? $request->shipping_label : null,
                'shipping_cost' => 0,
            ]);
            $order->update(['system_id' => $this->buildSystemIdForOrder($order->id, $firstVariantId)]);

            $userTierId = $request->user()->tier_id;
            $printCost = 0;
            $totalQty = 0;
            $refVariantId = null;
            foreach ($request->items as $itemData) {
                $price = $this->getVariantPrice($itemData['product_variant_id'], $userTierId);
                $quantity = max(1, (int)($itemData['quantity'] ?? 1));

                // Validate accessory price belongs to user's tier
                $accessoryItemId = null;
                $accessoryUnitPrice = 0;
                if (!empty($itemData['accessory_item_id'])) {
                    $accPrice = AccessoryPrice::find($itemData['accessory_item_id']);
                    if ($accPrice && $accPrice->tier_id === $userTierId) {
                        $accessoryItemId = $accPrice->id;
                        $accessoryUnitPrice = (float)$accPrice->price;
                    }
                }

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $itemData['product_variant_id'],
                    'accessory_item_id' => $accessoryItemId,
                    'mockup_front' => $itemData['mockup_front'] ?? null,
                    'mockup_back' => $itemData['mockup_back'] ?? null,
                    'order_type' => $itemData['order_type'] ?? 0,
                    'price' => $price,
                    'quantity' => $quantity,
                ]);
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

            $shippingCost = $this->computeOrderShipping($refVariantId, $userTierId, $hasLabel, $totalQty);

            $order->update([
                'print_cost' => $printCost,
                'shipping_cost' => $shippingCost,
                'total_cost' => $printCost + $shippingCost,
            ]);

            return response()->json([
                'message' => 'Order created',
                'order' => $order->fresh()->load(['items.metas', 'items.productVariant', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'address']),
            ], 201);
        });
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'shipping_label' => 'nullable|string',
            'tracking_id' => 'nullable|string',
            'shipping_cost' => 'nullable|numeric|min:0',
            'print_cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|integer|in:0,1,2,3,4,5,6',
        ]);

        $data = $request->only('shipping_label', 'tracking_id', 'shipping_cost', 'print_cost', 'status');

        // Recalculate total_cost if costs changed
        if ($request->has('shipping_cost') || $request->has('print_cost')) {
            $printCost = $request->print_cost ?? $order->print_cost;
            $shippingCost = $request->shipping_cost ?? $order->shipping_cost;
            $data['total_cost'] = $printCost + $shippingCost;
        }

        if (isset($data['status']) && $data['status'] == Order::STATUS_SHIPPED) {
            $data['completed_time'] = now();
        }

        $order->update($data);

        return response()->json([
            'message' => 'Order updated',
            'order' => $order->fresh()->load(['items.metas', 'items.productVariant', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'address']),
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

        return response()->json([
            'message' => "Deleted {$deleted} order(s)",
            'count' => $deleted,
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

    public function updateStatus(Request $request, Order $order)
    {
        $request->validate(['status' => 'required|integer|in:0,1,2,3,4,5,6']);

        $data = ['status' => $request->status];
        if ($request->status == Order::STATUS_SHIPPED) {
            $data['completed_time'] = now();
        }

        $order->update($data);
        return response()->json(['message' => 'Status updated', 'order' => $order->fresh()]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'status' => 'required|integer|in:0,1,2,3,4,5,6',
        ]);

        $data = ['status' => $request->status];
        if ($request->status == Order::STATUS_SHIPPED) {
            $data['completed_time'] = now();
        }

        Order::whereIn('id', $request->order_ids)->update($data);

        return response()->json([
            'message' => 'Bulk status updated',
            'count' => count($request->order_ids),
        ]);
    }

    public function pay(Request $request, Order $order)
    {
        $user = $order->user;

        if ($order->paid_cost >= $order->total_cost) {
            return response()->json(['message' => 'Order already paid'], 400);
        }

        $amountToPay = $order->total_cost - $order->paid_cost;

        if ($user->wallet < $amountToPay) {
            return response()->json(['message' => 'Insufficient wallet balance', 'required' => $amountToPay, 'wallet' => $user->wallet], 400);
        }

        return DB::transaction(function () use ($user, $order, $amountToPay) {
            $user->decrement('wallet', $amountToPay);
            $order->update(['paid_cost' => $order->total_cost]);

            WalletTransaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'type' => 'paid',
                'amount' => -$amountToPay,
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

    public function bulkPay(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
        ]);

        $user = $request->user();
        $orders = Order::whereIn('id', $request->order_ids)
            ->where('user_id', $user->id)
            ->get();

        $totalToPay = 0;
        foreach ($orders as $order) {
            if ($order->paid_cost < $order->total_cost) {
                $totalToPay += ($order->total_cost - $order->paid_cost);
            }
        }

        if ($totalToPay == 0) {
            return response()->json(['message' => 'All orders already paid'], 400);
        }

        if ($user->wallet < $totalToPay) {
            return response()->json(['message' => 'Insufficient wallet balance', 'required' => $totalToPay, 'wallet' => $user->wallet], 400);
        }

        return DB::transaction(function () use ($user, $orders, $totalToPay) {
            $paidCount = 0;
            foreach ($orders as $order) {
                $amountToPay = $order->total_cost - $order->paid_cost;
                if ($amountToPay <= 0) continue;

                $user->decrement('wallet', $amountToPay);
                $order->update(['paid_cost' => $order->total_cost]);

                WalletTransaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => 'paid',
                    'amount' => -$amountToPay,
                    'balance_after' => $user->fresh()->wallet,
                    'note' => "Paid order #{$order->system_id}",
                ]);
                $paidCount++;
            }

            return response()->json([
                'message' => "Paid {$paidCount} orders",
                'total_paid' => $totalToPay,
                'wallet' => $user->fresh()->wallet,
            ]);
        });
    }

    public function importCsv(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt']);

        $file = $request->file('file');
        $rows = array_map('str_getcsv', file($file->getPathname()));
        $header = array_map('trim', array_shift($rows));

        $user = $request->user();
        $created = [];
        $errors = [];

        return DB::transaction(function () use ($rows, $header, $user, &$created, &$errors) {
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
                $hasLabel = !empty($data['shipping_label']);
                $hasAddress = !empty($data['first_name']) && !empty($data['address_1']) && !empty($data['city']) && !empty($data['zipcode']) && !empty($data['country']);

                if (!$hasLabel && !$hasAddress) {
                    $errors[] = "Line {$lineNum}: must provide either shipping_label or full address (first_name, address_1, city, zipcode, country)";
                    continue;
                }

                $shipType = $hasLabel ? 'tiktok_ship' : 'seller_ship';

                // Resolve accessory price: prefer accessory_code (filtered by user tier), fallback to accessory_item_id.
                // If the line specified an accessory but it could not be resolved, skip the entire row — do NOT create
                // an order with a missing/invalid accessory.
                $accessoryItemId = null;
                $accessoryUnitPrice = 0;
                if (!empty($data['accessory_code'])) {
                    $accPrice = AccessoryPrice::where('accessory_code', $data['accessory_code'])
                        ->where('tier_id', $user->tier_id)
                        ->first();
                    if ($accPrice) {
                        $accessoryItemId = $accPrice->id;
                        $accessoryUnitPrice = (float)$accPrice->price;
                    } else {
                        $errors[] = "Line {$lineNum}: accessory_code '{$data['accessory_code']}' not found for your tier (order not created)";
                        continue;
                    }
                } elseif (!empty($data['accessory_item_id'])) {
                    $accPrice = AccessoryPrice::find((int)$data['accessory_item_id']);
                    if ($accPrice && $accPrice->tier_id === $user->tier_id) {
                        $accessoryItemId = $accPrice->id;
                        $accessoryUnitPrice = (float)$accPrice->price;
                    } else {
                        $errors[] = "Line {$lineNum}: accessory_item_id not valid for your tier (order not created)";
                        continue;
                    }
                }

                $order = Order::create([
                    'user_id' => $user->id,
                    'system_id' => $this->generateSystemId(), // temporary
                    'ref_id' => !empty($data['ref_id']) ? $data['ref_id'] : null,
                    'status' => Order::STATUS_NEW_ORDER,
                    'ship_type' => $shipType,
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

                $price = $this->getVariantPrice($variantId, $user->tier_id);

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $variantId,
                    'accessory_item_id' => $accessoryItemId,
                    'mockup_front' => $data['mockup_front'] ?? null,
                    'mockup_back' => $data['mockup_back'] ?? null,
                    'order_type' => (int)($data['order_type'] ?? 0),
                    'price' => $price,
                    'quantity' => $quantity,
                ]);

                // Metas: meta_<key> columns → OrderItemMeta rows (skip empty)
                foreach (['front', 'back', 'left', 'right', 'neck', 'special'] as $metaKey) {
                    $col = 'meta_' . $metaKey;
                    if (!empty($data[$col])) {
                        OrderItemMeta::create([
                            'order_item_id' => $item->id,
                            'key' => $metaKey,
                            'value' => $data[$col],
                        ]);
                    }
                }

                $linePrint = ($price + $accessoryUnitPrice) * $quantity;
                $lineShipping = $this->computeOrderShipping($variantId, $user->tier_id, $hasLabel, $quantity);
                $order->update([
                    'print_cost' => $linePrint,
                    'shipping_cost' => $lineShipping,
                    'total_cost' => $linePrint + $lineShipping,
                ]);
                $created[] = $order->system_id;
            }

            return response()->json([
                'message' => 'CSV import completed',
                'created_count' => count($created),
                'error_count' => count($errors),
                'created_refs' => $created,
                'errors' => $errors,
            ]);
        });
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

    public function exportOrders(Request $request)
    {
        $query = Order::with(['items.productVariant', 'address', 'user']);

        if ($request->user()->hasRole('seller')) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $orders = $query->orderBy('id', 'desc')->get();

        $callback = function () use ($orders) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['system_id', 'ref_id', 'status', 'ship_type', 'tracking_id', 'print_cost', 'shipping_cost', 'total_cost', 'paid_cost', 'first_name', 'last_name', 'address_1', 'city', 'state', 'zipcode', 'country', 'created_at']);

            foreach ($orders as $order) {
                $addr = $order->address;
                fputcsv($file, [
                    $order->system_id,
                    $order->ref_id,
                    Order::STATUS_MAP[$order->status] ?? 'unknown',
                    $order->ship_type,
                    $order->tracking_id,
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
            }
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="orders_export_' . date('Y-m-d') . '.csv"',
        ]);
    }

    public function fulfillByApiKey(Request $request)
    {
        $user = $request->get('api_user');
        $hasLabel = !empty($request->shipping_label);

        $request->validate([
            'ref_id' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.accessory_item_id' => 'nullable|exists:accessory_prices,id',
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

        return DB::transaction(function () use ($request, $user, $hasLabel) {
            $firstVariantId = (int) ($request->items[0]['product_variant_id'] ?? 0) ?: null;
            $order = Order::create([
                'user_id' => $user->id,
                'system_id' => $this->generateSystemId(), // temporary, updated after we have order.id
                'ref_id' => $request->ref_id ?: null,
                'status' => Order::STATUS_NEW_ORDER,
                'ship_type' => $hasLabel ? 'tiktok_ship' : 'seller_ship',
                'shipping_label' => $hasLabel ? $request->shipping_label : null,
                'shipping_cost' => 0,
            ]);
            $order->update(['system_id' => $this->buildSystemIdForOrder($order->id, $firstVariantId)]);

            $userTierId = $user->tier_id;
            $printCost = 0;
            $totalQty = 0;
            $refVariantId = null;
            foreach ($request->items as $itemData) {
                $price = $this->getVariantPrice($itemData['product_variant_id'], $userTierId);
                $quantity = max(1, (int)($itemData['quantity'] ?? 1));

                $accessoryItemId = null;
                $accessoryUnitPrice = 0;
                if (!empty($itemData['accessory_item_id'])) {
                    $accPrice = AccessoryPrice::find($itemData['accessory_item_id']);
                    if ($accPrice && $accPrice->tier_id === $userTierId) {
                        $accessoryItemId = $accPrice->id;
                        $accessoryUnitPrice = (float)$accPrice->price;
                    }
                }

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $itemData['product_variant_id'],
                    'accessory_item_id' => $accessoryItemId,
                    'mockup_front' => $itemData['mockup_front'] ?? null,
                    'mockup_back' => $itemData['mockup_back'] ?? null,
                    'order_type' => $itemData['order_type'] ?? 0,
                    'price' => $price,
                    'quantity' => $quantity,
                ]);
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

            $shippingCost = $this->computeOrderShipping($refVariantId, $userTierId, $hasLabel, $totalQty);

            $order->update([
                'print_cost' => $printCost,
                'shipping_cost' => $shippingCost,
                'total_cost' => $printCost + $shippingCost,
            ]);

            return response()->json([
                'message' => 'Order created via API key',
                'order' => $order->fresh()->load(['items.metas', 'items.productVariant', 'items.accessoryPrice.accessory', 'items.accessoryPrice.tier', 'address']),
            ], 201);
        });
    }

    private function getVariantPrice(int $variantId, ?int $tierId): float
    {
        return $this->getVariantPriceByKey($variantId, $tierId, 'base_cost');
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
}
