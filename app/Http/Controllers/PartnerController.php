<?php

namespace App\Http\Controllers;

use App\Models\Gangsheet;
use App\Models\Order;
use App\Models\OrderItemMeta;
use Illuminate\Http\Request;

/**
 * Endpoints for the partner-bullstart app. A partner only ever sees the
 * gangsheets an admin assigned to them (gangsheet_partner pivot). See
 * doc/partner.md.
 */
class PartnerController extends Controller
{
    private const QR_KEY_REGEX = '_qr(_[0-9]+)?$';

    /** Distinct order ids across all gangsheets assigned to this partner. */
    private function assignedOrderIds($user): array
    {
        $gangs = $user->assignedGangsheets()->get(['gangsheets.id', 'order_ids']);
        $ids = [];
        foreach ($gangs as $g) {
            foreach (($g->order_ids ?? []) as $oid) $ids[(int) $oid] = true;
        }
        return array_keys($ids);
    }

    /** Dashboard totals: assigned gangsheets + distinct orders across them. */
    public function dashboard(Request $request)
    {
        $user = $request->user();

        $gangs = $user->assignedGangsheets()->get(['gangsheets.id', 'order_ids', 'metas_count']);
        $orderIds = [];
        foreach ($gangs as $g) {
            foreach (($g->order_ids ?? []) as $oid) $orderIds[$oid] = true;
        }

        // Doanh thu được phân chia cho partner này (tổng partner_revenue trên
        // các đơn admin đã gán cho họ). locked = đã chốt (đánh dấu đã in).
        $revenue = Order::where('partner_id', $user->id)
            ->selectRaw('COALESCE(SUM(partner_revenue),0) as total, '
                . 'COALESCE(SUM(CASE WHEN partner_locked_at IS NOT NULL THEN partner_revenue ELSE 0 END),0) as locked, '
                . 'COUNT(*) as cnt')
            ->first();

        return response()->json([
            'total_gangsheets' => $gangs->count(),
            'total_orders'     => count($orderIds),
            'total_metas'      => (int) $gangs->sum('metas_count'),
            'revenue_total'    => round((float) ($revenue->total ?? 0), 2),
            'revenue_locked'   => round((float) ($revenue->locked ?? 0), 2),
            'revenue_orders'   => (int) ($revenue->cnt ?? 0),
        ]);
    }

    /** Paginated list of the partner's assigned gangsheets (same shape as admin index). */
    public function gangsheets(Request $request)
    {
        $query = $request->user()->assignedGangsheets()
            ->with('creator:id,name')
            ->orderBy('gangsheets.id', 'desc');

        if ($request->filled('date_from')) $query->whereDate('gangsheets.created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('gangsheets.created_at', '<=', $request->date_to);
        if ($request->filled('line_id'))   $query->where('gangsheets.line_id', $request->line_id);
        if ($request->filled('page_format')) {
            if ($request->page_format === 'letter') {
                $query->where(fn ($q) => $q->where('gangsheets.page_format', 'letter')->orWhereNull('gangsheets.page_format'));
            } else {
                $query->where('gangsheets.page_format', $request->page_format);
            }
        }

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    /** One assigned gangsheet + its orders. 404 if not assigned to this partner. */
    public function show(Request $request, Gangsheet $gangsheet)
    {
        $assigned = $request->user()->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists();
        if (!$assigned) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $gangsheet->load('creator:id,name');
        $orderIds = $gangsheet->order_ids ?? [];
        $orders = empty($orderIds)
            ? collect()
            : Order::whereIn('id', $orderIds)
                ->select('id', 'system_id', 'ref_id', 'status', 'total_cost',
                    'partner_id', 'partner_revenue', 'partner_locked_at')
                ->orderBy('id', 'asc')
                ->get();

        return response()->json(['gangsheet' => $gangsheet, 'orders' => $orders]);
    }

    /** Partner toggles the "đã in" mark on one of their assigned gangsheets. */
    public function markPrinted(Request $request, Gangsheet $gangsheet)
    {
        $request->validate(['printed' => 'required|boolean']);
        $user = $request->user();
        if (!$user->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $printedAt = $request->boolean('printed') ? now() : null;
        $user->assignedGangsheets()->updateExistingPivot($gangsheet->id, ['printed_at' => $printedAt]);

        // Lock / unlock the revenue split of this partner's orders in the gang.
        // Once printed, admin can no longer change the partner or the amount;
        // un-printing reverses it so a mistaken mark can be corrected.
        $orderIds = $gangsheet->order_ids ?? [];
        if (!empty($orderIds)) {
            Order::whereIn('id', $orderIds)
                ->where('partner_id', $user->id)
                ->update(['partner_locked_at' => $printedAt]);
        }

        return response()->json(['gangsheet_id' => $gangsheet->id, 'printed_at' => $printedAt]);
    }

    /**
     * Orders the partner may compose / reconvert: orders whose partner_id
     * matches the current user. Same eager-load shape as the admin
     * lookup-orders so the desktop compose pipeline can build the PDF. These
     * orders are already production=true (the admin ganged them), so the client
     * builds with includeProduced=true (re-gang semantics).
     */
    public function myOrders(Request $request)
    {
        $user = $request->user();

        $orders = Order::with([
            'items.metas' => fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX),
            'items.productVariant.product:id,name,line_id,order_type',
            'items.material:id,name',
            'items.accessoryPrice.accessory:id,name,product_id,gangsheet_split',
            'items.accessoryPrices.accessory:id,name,product_id,gangsheet_split',
        ])
            ->where('partner_id', $user->id)
            ->where('status', '!=', Order::STATUS_SHIPPED)
            ->orderBy('id', 'asc')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * Full detail for ONE order assigned to this partner — same shape as the
     * admin order page minus everything commercial.
     *
     * The stripping happens HERE, not in the partner UI: an endpoint that
     * returns the seller's name and the order total is one screen away from
     * leaking them, however the client renders it. `partner_revenue` stays —
     * that is the partner's own money, not the seller's price.
     */
    public function showOrder(Request $request, Order $order)
    {
        $user = $request->user();
        // 404, not 403: whether an order exists is not a partner's business
        // unless it is theirs.
        abort_unless($order->partner_id === $user->id, 404, 'Order not found');

        $order->load([
            'items.metas',
            'items.productVariant.product:id,name,line_id,order_type',
            'items.material:id,name',
            'items.accessoryPrice.accessory:id,name',
            'items.accessoryPrices.accessory:id,name',
            'address',
        ]);

        // Seller identity: user_id is dropped and the `user` relation is never
        // loaded above.
        $order->makeHidden(['user_id', 'print_cost', 'shipping_cost', 'total_cost', 'paid_cost', 'base_support']);
        foreach ($order->items as $item) {
            $item->makeHidden('price');
            $item->accessoryPrice?->makeHidden('price');
            foreach ($item->accessoryPrices as $ap) {
                $ap->makeHidden('price');
            }
        }

        return response()->json(['order' => $order]);
    }

    /**
     * B2 credentials so the partner desktop app can upload its composed PDF
     * directly to Backblaze (same contract as GangsheetController). Date-scoped
     * folder; the client appends the filename.
     */
    public function storageCredentials(Request $request)
    {
        $cfg = config('filesystems.disks.b2');
        return response()->json([
            'access_key_id' => $cfg['key'] ?? null,
            'secret_access_key' => $cfg['secret'] ?? null,
            'region' => $cfg['region'] ?? 'us-west-004',
            'endpoint' => $cfg['endpoint'] ?? null,
            'bucket' => $cfg['bucket'] ?? null,
            'public_url_base' => rtrim($cfg['url'] ?? '', '/'),
            'folder' => 'gangsheet/' . now()->format('Y-m-d'),
        ]);
    }

    /**
     * Record a gangsheet the partner composed + uploaded, then auto-assign it to
     * that partner so it shows up in their list. Every order must belong to a
     * gang already assigned to this partner (a partner can only re-gang their
     * own orders, never reach an arbitrary order id).
     */
    public function storeGangsheet(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'file_url' => 'required|url',
            'line_id' => 'nullable|string|max:16',
            'page_format' => 'nullable|string|max:16',
            'first_system_id' => 'nullable|string',
            'last_system_id' => 'nullable|string',
            'orders_count' => 'required|integer|min:1',
            'metas_count' => 'required|integer|min:1',
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
            'meta_ids' => 'required|array|min:1',
            'meta_ids.*' => 'integer',
        ]);

        $user = $request->user();
        $allowed = array_flip($this->assignedOrderIds($user));
        $orderIds = array_values(array_unique(array_map('intval', $request->order_ids)));
        foreach ($orderIds as $oid) {
            if (!isset($allowed[$oid])) {
                return response()->json(['message' => "Order {$oid} is not assigned to you"], 403);
            }
        }
        $metaIds = array_values(array_unique(array_map('intval', $request->meta_ids)));

        try {
            $gangsheet = app(\App\Services\GangsheetService::class)->record([
                'filename'        => $request->filename,
                'file_url'        => $request->file_url,
                'line_id'         => $request->line_id,
                'page_format'     => $request->page_format,
                'first_system_id' => $request->first_system_id,
                'last_system_id'  => $request->last_system_id,
                'orders_count'    => (int) $request->orders_count,
                'metas_count'     => (int) $request->metas_count,
                'order_ids'       => $orderIds,
                'meta_ids'        => $metaIds,
            ], $user->id);
        } catch (\Throwable $e) {
            \Log::error('[partner-gangsheet] DB save failed: ' . $e->getMessage(), ['filename' => $request->filename]);
            return response()->json(['message' => 'Failed to record gang sheet: ' . $e->getMessage()], 500);
        }

        // Auto-assign the new gang to this partner (keep any other partners).
        $gangsheet->partners()->syncWithoutDetaching([$user->id]);

        return response()->json([
            'message' => 'Gangsheet saved',
            'gangsheet' => $gangsheet->load('creator:id,name'),
        ], 201);
    }

    /**
     * Attach per-page PNG exports to a gangsheet the partner owns.
     * Same contract as GangsheetController::updatePngUrls but scoped to
     * the partner's assigned gangsheets.
     */
    public function updatePngUrls(Request $request, Gangsheet $gangsheet)
    {
        $user = $request->user();
        if (!$user->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'png_urls' => 'required|array|min:1',
            'png_urls.*' => 'required|url',
            'force' => 'sometimes|boolean',
        ]);

        if ($gangsheet->png_urls && !$request->boolean('force')) {
            return response()->json(['message' => 'PNG đã tồn tại. Gửi force=true để ghi đè.'], 409);
        }

        $gangsheet->update(['png_urls' => $request->png_urls]);
        return response()->json(['gangsheet' => $gangsheet->only('id', 'png_urls')]);
    }

    /**
     * Reconvert all orders in one of the partner's assigned gangsheets.
     * Convenience wrapper so the partner doesn't need to list order IDs
     * manually — the gang's order_ids field is used.
     */
    public function reconvertGangsheet(Request $request, Gangsheet $gangsheet)
    {
        $user = $request->user();
        if (!$user->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $orderIds = $gangsheet->order_ids ?? [];
        if (empty($orderIds)) {
            return response()->json(['message' => 'Gangsheet này không có đơn nào', 'count' => 0], 200);
        }

        $allowed = array_flip($this->assignedOrderIds($user));
        $orderIds = array_values(array_filter(
            array_unique(array_map('intval', $orderIds)),
            fn ($id) => isset($allowed[$id])
        ));

        $orders = Order::whereIn('id', $orderIds)->get();
        $totalDeleted = 0;
        foreach ($orders as $order) {
            $itemIds = $order->items()->pluck('id');
            $totalDeleted += OrderItemMeta::whereIn('order_item_id', $itemIds)
                ->where('key', 'regexp', self::QR_KEY_REGEX)
                ->delete();
            $order->update(['production' => false]);
        }

        return response()->json([
            'message' => "Reconvert queued cho {$orders->count()} đơn; xoá {$totalDeleted} _qr meta(s).",
            'count' => $orders->count(),
            'deleted' => $totalDeleted,
        ]);
    }

    /**
     * Reconvert the _qr designs of the partner's own orders: delete their _qr
     * metas + unset production so the converter cron rebuilds them from the
     * mockup URLs. Scoped — only orders inside this partner's assigned gangs.
     */
    public function reconvert(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
        ]);

        $allowed = array_flip($this->assignedOrderIds($request->user()));
        $orderIds = array_values(array_filter(
            array_unique(array_map('intval', $request->order_ids)),
            fn ($id) => isset($allowed[$id])
        ));
        if (empty($orderIds)) {
            return response()->json(['message' => 'No accessible orders to reconvert', 'count' => 0, 'deleted' => 0], 403);
        }

        $orders = Order::whereIn('id', $orderIds)->get();
        $totalDeleted = 0;
        foreach ($orders as $order) {
            $itemIds = $order->items()->pluck('id');
            $totalDeleted += OrderItemMeta::whereIn('order_item_id', $itemIds)
                ->where('key', 'regexp', self::QR_KEY_REGEX)
                ->delete();
            $order->update(['production' => false]);
        }

        return response()->json([
            'message' => "Reconvert queued for {$orders->count()} order(s); removed {$totalDeleted} _qr meta(s). The converter cron will rebuild them.",
            'count' => $orders->count(),
            'deleted' => $totalDeleted,
            'skipped' => count($request->order_ids) - $orders->count(),
        ]);
    }
}
