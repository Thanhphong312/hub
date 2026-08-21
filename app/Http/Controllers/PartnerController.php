<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FiltersOrderList;
use App\Models\Gangsheet;
use App\Models\Order;
use App\Models\OrderItemMeta;
use App\Models\PartnerPayout;
use App\Models\Ticket;
use App\Services\GangsheetService;
use Illuminate\Http\Request;

/**
 * Endpoints for the partner-bullstart app. A partner only ever sees the
 * gangsheets an admin assigned to them (gangsheet_partner pivot). See
 * doc/partner.md.
 */
class PartnerController extends Controller
{
    // Same order filters as the admin Orders page — see orderIndex().
    use FiltersOrderList;

    private const QR_KEY_REGEX = '_qr(_[0-9]+)?$';

    /** Distinct order ids across all gangsheets assigned to this partner. */
    private function assignedOrderIds($user): array
    {
        $gangs = $user->assignedGangsheets()->get(['gangsheets.id', 'order_ids']);
        $ids = [];
        foreach ($gangs as $g) {
            foreach (($g->order_ids ?? []) as $oid) {
                $ids[(int) $oid] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Order ids this partner may build a gangsheet from.
     *
     * Two notions of "assigned" exist and both have to count. orders.partner_id
     * is how orders are handed out now — it drives myOrders, orderIndex,
     * lookupOrders and showOrder, so it is what the partner actually sees on
     * screen. The older notion is membership of a gangsheet assigned to them,
     * which is all that exists for orders ganged before partner_id was added.
     *
     * Checking only the gang pivot (as this guard used to) rejected every order
     * handed over by partner_id that was not already inside one of their gangs:
     * the partner could list it and pick it, then have the save refused.
     */
    private function composableOrderIds($user): array
    {
        $ids = array_flip($this->assignedOrderIds($user));
        foreach (Order::where('partner_id', $user->id)->pluck('id') as $oid) {
            $ids[(int) $oid] = true;
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
            foreach (($g->order_ids ?? []) as $oid) {
                $orderIds[$oid] = true;
            }
        }

        // Doanh thu được phân chia cho partner này (tổng partner_revenue trên
        // các đơn admin đã gán cho họ). locked = đã chốt (đánh dấu đã in).
        // Split by whether the seller has settled the order, using the hub's
        // own definition of unpaid (paid_cost < total_cost). This is the
        // customer's payment, never the partner's payout — the amounts summed
        // here are always partner_revenue, so the labels must say whose payment
        // is meant or a partner will read it as money owed to them.
        $revenue = Order::where('partner_id', $user->id)
            ->selectRaw('COALESCE(SUM(partner_revenue),0) as total, '
                .'COALESCE(SUM(CASE WHEN partner_locked_at IS NOT NULL THEN partner_revenue ELSE 0 END),0) as locked, '
                .'COALESCE(SUM(CASE WHEN paid_cost >= total_cost THEN partner_revenue ELSE 0 END),0) as paid, '
                .'COALESCE(SUM(CASE WHEN paid_cost < total_cost THEN partner_revenue ELSE 0 END),0) as unpaid, '
                .'SUM(CASE WHEN paid_cost >= total_cost THEN 1 ELSE 0 END) as paid_cnt, '
                .'SUM(CASE WHEN paid_cost < total_cost THEN 1 ELSE 0 END) as unpaid_cnt, '
                .'COUNT(*) as cnt')
            ->first();

        return response()->json([
            'total_gangsheets' => $gangs->count(),
            'total_orders' => count($orderIds),
            'total_metas' => (int) $gangs->sum('metas_count'),
            'revenue_total' => round((float) ($revenue->total ?? 0), 2),
            'revenue_locked' => round((float) ($revenue->locked ?? 0), 2),
            'revenue_orders' => (int) ($revenue->cnt ?? 0),
            'revenue_paid' => round((float) ($revenue->paid ?? 0), 2),
            'revenue_unpaid' => round((float) ($revenue->unpaid ?? 0), 2),
            'orders_paid' => (int) ($revenue->paid_cnt ?? 0),
            'orders_unpaid' => (int) ($revenue->unpaid_cnt ?? 0),
        ]);
    }

    /**
     * The partner's own payout history and where they stand.
     *
     * Same figures the admin side computes, scoped to the caller — a partner
     * has an obvious interest in seeing what they were paid, and having to ask
     * for a screenshot of someone else's screen is not a record.
     */
    public function payouts(Request $request)
    {
        $user = $request->user();

        $earned = round((float) Order::where('partner_id', $user->id)
            ->where('status', Order::STATUS_SHIPPED)
            ->sum('partner_revenue'), 2);
        $paid = round((float) PartnerPayout::where('user_id', $user->id)->sum('amount'), 2);

        return response()->json([
            'earned' => $earned,
            'paid' => $paid,
            'owed' => round($earned - $paid, 2),
            'orders' => Order::where('partner_id', $user->id)
                ->where('status', Order::STATUS_SHIPPED)
                ->whereNotNull('partner_revenue')
                ->count(),
            'unpriced' => Order::where('partner_id', $user->id)
                ->where('status', Order::STATUS_SHIPPED)
                ->whereNull('partner_revenue')
                ->count(),
            'payouts' => PartnerPayout::where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'amount', 'balance_after', 'method', 'transaction_id', 'period_from', 'period_to', 'note', 'created_at']),
        ]);
    }

    /** Paginated list of the partner's assigned gangsheets (same shape as admin index). */
    public function gangsheets(Request $request)
    {
        $query = $request->user()->assignedGangsheets()
            ->with('creator:id,name')
            ->orderBy('gangsheets.id', 'desc');

        // Channel scope, same rule as the admin list: no param means 'normal',
        // which is what every existing client sends, and gangs written before
        // the column existed default to normal too. 'all' opts out.
        if (strtolower(trim((string) $request->input('source'))) !== 'all') {
            $query->where('gangsheets.source', Order::normalizeSource($request->input('source')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('gangsheets.created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('gangsheets.created_at', '<=', $request->date_to);
        }
        if ($request->filled('line_id')) {
            $query->where('gangsheets.line_id', $request->line_id);
        }
        if ($request->filled('page_format')) {
            if ($request->page_format === 'letter') {
                $query->where(fn ($q) => $q->where('gangsheets.page_format', 'letter')->orWhereNull('gangsheets.page_format'));
            } else {
                $query->where('gangsheets.page_format', $request->page_format);
            }
        }

        // Only gangs with work left — same filter as the admin list.
        if ($request->boolean('unshipped')) {
            $query->hasUnshippedOrders();
        }

        $paginated = $query->paginate($request->get('per_page', 20));

        // How much of each gang is still outstanding. order_ids is a JSON
        // column, so there is no relation to withCount — resolve every id on
        // the page in ONE query and count in PHP rather than a query per gang.
        // `orders_count` is what the gang was built from and never moves; this
        // is what is left to ship today.
        $pageOrderIds = collect($paginated->items())
            ->flatMap(fn ($g) => $g->order_ids ?? [])
            ->map(fn ($id) => (int) $id)->unique()->values();

        $unshipped = $pageOrderIds->isEmpty()
            ? collect()
            : Order::whereIn('id', $pageOrderIds)
                ->where('status', '!=', Order::STATUS_SHIPPED)
                ->pluck('id')->flip();

        $paginated->getCollection()->each(function ($g) use ($unshipped) {
            $g->unshipped_orders_count = collect($g->order_ids ?? [])
                ->filter(fn ($id) => $unshipped->has((int) $id))->count();
        });

        return response()->json($paginated);
    }

    /**
     * Partner deletes one of their own gangsheets.
     *
     * Only the record goes: the orders and their _qr metas keep production=true,
     * exactly as the admin delete behaves. Removing the row is how a partner
     * clears a sheet they rebuilt or ganged by mistake; it does not un-produce
     * the work.
     *
     * Refused once any partner has marked it printed — at that point the
     * revenue split is locked against it and deleting would strand that.
     */
    public function destroyGangsheet(Request $request, Gangsheet $gangsheet)
    {
        $user = $request->user();
        if (! $user->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $printed = $user->assignedGangsheets()
            ->where('gangsheets.id', $gangsheet->id)
            ->wherePivotNotNull('printed_at')
            ->exists();
        if ($printed) {
            return response()->json([
                'message' => 'Gang này đã đánh dấu đã in — bỏ tick "Đã in" trước khi xoá.',
            ], 422);
        }

        $filename = $gangsheet->filename;
        $gangsheet->delete();

        return response()->json(['message' => 'Đã xoá '.$filename]);
    }

    /** One assigned gangsheet + its orders. 404 if not assigned to this partner. */
    public function show(Request $request, Gangsheet $gangsheet)
    {
        $assigned = $request->user()->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists();
        if (! $assigned) {
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
        if (! $user->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $printedAt = $request->boolean('printed') ? now() : null;
        $user->assignedGangsheets()->updateExistingPivot($gangsheet->id, ['printed_at' => $printedAt]);

        // Lock / unlock the revenue split of this partner's orders in the gang.
        // Once printed, admin can no longer change the partner or the amount;
        // un-printing reverses it so a mistaken mark can be corrected.
        $orderIds = $gangsheet->order_ids ?? [];
        if (! empty($orderIds)) {
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
            // Compose one channel at a time: an FPT sheet must never be built
            // out of normal orders, and vice-versa.
            ->ofSource($request->input('source'))
            ->where('status', '!=', Order::STATUS_SHIPPED)
            ->orderBy('id', 'asc')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * Look up the partner's orders by an explicit list of system_ids — the
     * Find / Re-gang tab, which rebuilds a sheet for orders that already have
     * production=true.
     *
     * Same eager-load shape as myOrders() so the desktop compose pipeline can
     * build straight from the result, and the same row scope: an id belonging
     * to someone else's order comes back under `missing`, never as data.
     */
    public function lookupOrders(Request $request)
    {
        $request->validate([
            'system_ids' => 'required|array|min:1',
            'system_ids.*' => 'string',
        ]);

        $user = $request->user();
        $ids = array_values(array_unique(array_map('trim', $request->system_ids)));

        $orders = Order::with([
            'items.metas' => fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX),
            'items.productVariant.product:id,name,line_id,order_type',
            'items.material:id,name',
            'items.accessoryPrice.accessory:id,name,product_id,gangsheet_split',
            'items.accessoryPrices.accessory:id,name,product_id,gangsheet_split',
        ])
            ->where('partner_id', $user->id)
            ->whereIn('system_id', $ids)
            ->orderBy('id', 'asc')
            ->get();

        $orders->each(function ($o) {
            $o->makeHidden(['user_id', 'print_cost', 'shipping_cost', 'total_cost', 'paid_cost', 'base_support']);
            foreach ($o->items as $item) {
                $item->makeHidden('price');
                $item->accessoryPrice?->makeHidden('price');
                foreach ($item->accessoryPrices as $ap) {
                    $ap->makeHidden('price');
                }
            }
        });

        $found = $orders->pluck('system_id')->all();

        return response()->json([
            'orders' => $orders,
            'found' => $found,
            'missing' => array_values(array_diff($ids, $found)),
        ]);
    }

    /**
     * Searchable, paginated list of the orders assigned to this partner.
     *
     * Separate from myOrders(): that one feeds the compose pipeline and returns
     * every unshipped order at once, unpaginated. This is the browsing screen,
     * and it runs the SAME filter set as the admin Orders page (FiltersOrderList)
     * so a partner searching by system_id, ref_id, date or product behaves
     * exactly like an admin doing it.
     *
     * Prices and seller identity are stripped here as in showOrder().
     */
    public function orderIndex(Request $request)
    {
        $user = $request->user();

        $query = Order::with([
            'items.productVariant.product:id,name,line_id,order_type',
            'items.material:id,name',
            'items.accessoryPrice.accessory:id,name',
            'items.accessoryPrices.accessory:id,name',
            // Delivery state. Column list skips the heavy `raw` carrier JSON,
            // which nothing on the partner screens reads.
            'tracking' => fn ($q) => $q->select(
                'id', 'order_id', 'tracking_id', 'status', 'status_description',
                'date_intransit', 'delivered_at', 'last_event_at', 'checked_at'
            ),
        ])
            // Row scope FIRST — the shared filters authorise nothing.
            ->where('partner_id', $user->id);

        // Ticket markers, scoped to what a partner may read (both platforms, but
        // only on their own orders).
        $query->withCount([
            'tickets as tickets_count' => fn ($q) => $q->visibleTo($user),
            'tickets as open_tickets_count' => fn ($q) => $q->visibleTo($user)
                ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_NEW_MESSAGE]),
        ]);

        $this->applyOrderListFilters($query, $request);

        $orders = $query->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->get('per_page', 20))));

        $orders->getCollection()->each(function ($o) {
            $o->makeHidden(['user_id', 'print_cost', 'shipping_cost', 'total_cost', 'paid_cost', 'base_support']);
            foreach ($o->items as $item) {
                $item->makeHidden('price');
                $item->accessoryPrice?->makeHidden('price');
                foreach ($item->accessoryPrices as $ap) {
                    $ap->makeHidden('price');
                }
            }
        });

        return response()->json($orders);
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
            'tracking' => fn ($q) => $q->select(
                'id', 'order_id', 'tracking_id', 'status', 'status_description',
                'date_intransit', 'delivered_at', 'last_event_at', 'checked_at'
            ),
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
            'folder' => 'gangsheet/'.now()->format('Y-m-d'),
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
            // One PDF per page (card-skin gangs). file_url stays the first page.
            'pdf_urls' => 'nullable|array',
            'pdf_urls.*' => 'url',
            'png_urls' => 'nullable|array',
            'png_urls.*' => 'url',
            'line_id' => 'nullable|string|max:16',
            'source' => 'nullable|string|in:'.implode(',', Order::SOURCES),
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
        $allowed = array_flip($this->composableOrderIds($user));
        $orderIds = array_values(array_unique(array_map('intval', $request->order_ids)));
        foreach ($orderIds as $oid) {
            if (! isset($allowed[$oid])) {
                return response()->json(['message' => "Order {$oid} is not assigned to you"], 403);
            }
        }
        $metaIds = array_values(array_unique(array_map('intval', $request->meta_ids)));

        try {
            $gangsheet = app(GangsheetService::class)->record([
                'filename' => $request->filename,
                'file_url' => $request->file_url,
                'png_urls' => $request->input('png_urls'),
                'pdf_urls' => $request->input('pdf_urls'),
                'line_id' => $request->line_id,
                'source' => Order::normalizeSource($request->input('source')),
                'page_format' => $request->page_format,
                'first_system_id' => $request->first_system_id,
                'last_system_id' => $request->last_system_id,
                'orders_count' => (int) $request->orders_count,
                'metas_count' => (int) $request->metas_count,
                'order_ids' => $orderIds,
                'meta_ids' => $metaIds,
            ], $user->id);
        } catch (\Throwable $e) {
            \Log::error('[partner-gangsheet] DB save failed: '.$e->getMessage(), ['filename' => $request->filename]);

            return response()->json(['message' => 'Failed to record gang sheet: '.$e->getMessage()], 500);
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
        if (! $user->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'png_urls' => 'required|array|min:1',
            'png_urls.*' => 'required|url',
            'force' => 'sometimes|boolean',
        ]);

        if ($gangsheet->png_urls && ! $request->boolean('force')) {
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
        if (! $user->assignedGangsheets()->where('gangsheets.id', $gangsheet->id)->exists()) {
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
     * mockup URLs. Scoped to the orders the partner may work on — the same set
     * the Reconvert tab lists, which is partner_id as well as gang membership.
     */
    public function reconvert(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
        ]);

        $allowed = array_flip($this->composableOrderIds($request->user()));
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
