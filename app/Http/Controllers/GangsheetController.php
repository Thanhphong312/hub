<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Gangsheet;
use App\Models\Order;
use App\Models\PartnerAccessoryPrice;
use App\Models\PartnerVariantPrice;
use App\Models\User;
use App\Services\GangsheetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GangsheetController extends Controller
{
    private const QR_KEY_REGEX = '_qr(_[0-9]+)?$';

    public function index(Request $request)
    {
        $query = Gangsheet::with('creator:id,name', 'partners:id,name', 'downloadedBy:id,name')->orderBy('id', 'desc');

        // Channel scope. Gangs written before the column existed default to
        // 'normal', so the normal screen keeps listing everything it used to.
        if (strtolower(trim((string) $request->input('source'))) !== 'all') {
            $query->where('source', Order::normalizeSource($request->input('source')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('line_id')) {
            // Accept a LIST: one or many terms separated by comma / semicolon /
            // whitespace / newline. A gang matches if it matches ANY term.
            $terms = array_values(array_unique(array_filter(
                array_map('trim', preg_split('/[\s,;]+/', (string) $request->line_id))
            )));

            if (! empty($terms)) {
                $likes = array_map(fn ($t) => '%'.$t.'%', $terms);

                // Each term is matched as a "Line ID" OR an order "System ID".
                // system_id is "<line_id><order_id>" (e.g. PS_C + 3071 =
                // "PS_C3071"), so operators paste either into this one box.
                // Collect the order ids matching any term (by system_id or by
                // product line_id) so we can find the gangs that contain them.
                $orderIds = Order::where(function ($oq) use ($likes) {
                    foreach ($likes as $lk) {
                        $oq->orWhere('system_id', 'like', $lk)
                            ->orWhereHas('items.productVariant.product', fn ($q) => $q->where('line_id', 'like', $lk));
                    }
                })
                    ->orderByDesc('id')->limit(2000)->pluck('id')->all();

                $query->where(function ($q) use ($likes, $orderIds) {
                    foreach ($likes as $lk) {
                        $q->orWhere('line_id', 'like', $lk)
                            ->orWhere('first_system_id', 'like', $lk)
                            ->orWhere('last_system_id', 'like', $lk);
                    }
                    // JSON_CONTAINS is portable (MySQL 5.7+ / MariaDB 10.2+).
                    // One membership test per matched id; the order set is capped
                    // above so a broad term can't build a runaway query.
                    foreach ($orderIds as $oid) {
                        $q->orWhereRaw('JSON_CONTAINS(order_ids, ?)', [(string) (int) $oid]);
                    }
                });
            }
        }
        if ($request->filled('page_format')) {
            // Legacy gangs (null format) were Letter 11×8.5 → group them there.
            if ($request->page_format === 'letter') {
                $query->where(fn ($q) => $q->where('page_format', 'letter')->orWhereNull('page_format'));
            } else {
                $query->where('page_format', $request->page_format);
            }
        }

        // Only gangs with work left. Applied to the QUERY, not the page, so
        // pagination counts what it actually returns.
        if ($request->boolean('unshipped')) {
            $query->hasUnshippedOrders();
        }

        // Gangs nobody has been given yet — the ones still waiting to be handed
        // out. A plain relation check, unlike the JSON order_ids filters above.
        if ($request->boolean('no_partner')) {
            $query->whereDoesntHave('partners');
        }

        $paginated = $query->paginate($request->get('per_page', 20));

        // Duplicate detection: a gang is flagged when it shares an order_id with
        // another gang (the same order ended up on more than one gangsheet).
        // Cheap to do in PHP across all gangs — there are only a few dozen.
        $orderToGangs = [];
        foreach (Gangsheet::select('id', 'order_ids')->get() as $g) {
            foreach (($g->order_ids ?? []) as $oid) {
                $orderToGangs[(int) $oid][] = $g->id;
            }
        }
        $paginated->getCollection()->each(function ($g) use ($orderToGangs) {
            $others = [];
            foreach (($g->order_ids ?? []) as $oid) {
                foreach ($orderToGangs[(int) $oid] ?? [] as $gid) {
                    if ($gid !== $g->id) {
                        $others[$gid] = true;
                    }
                }
            }
            $g->is_duplicate = ! empty($others);
            $g->duplicate_gang_ids = array_values(array_map('intval', array_keys($others)));
        });

        // How much of each gang is still outstanding. order_ids is a JSON
        // column, so there is no relation to withCount — instead resolve every
        // id on the page in ONE query and count in PHP, rather than a query per
        // gang. `orders_count` on the row is what the gang was built from and
        // never changes; this is what is left to ship today.
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

    /** Toggle the "downloaded / đã làm" mark (shared across machines). */
    public function toggleDownloaded(Request $request, Gangsheet $gangsheet)
    {
        if ($gangsheet->downloaded_at) {
            $gangsheet->update(['downloaded_at' => null, 'downloaded_by_user_id' => null]);
        } else {
            $gangsheet->update(['downloaded_at' => now(), 'downloaded_by_user_id' => $request->user()->id]);
        }

        return response()->json($gangsheet->fresh()->load('downloadedBy:id,name'));
    }

    /** Users with the 'partner' role — for the admin "assign to partner" picker. */
    public function partnerUsers()
    {
        return response()->json(
            User::whereHas('role', fn ($q) => $q->where('slug', 'partner'))
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Admin sets which partner users a gangsheet is assigned to.
     *
     * The pivot alone only grants file access — the partner app reads its
     * ORDER list, revenue and printed-lock off `orders.partner_id`. So when the
     * gang is handed to exactly ONE partner, that id is stamped onto the gang's
     * orders too; otherwise the partner would see the sheet with an empty order
     * list behind it.
     *
     * Exactly one, because `orders.partner_id` holds a single owner: with two
     * partners on a gang there is no non-arbitrary choice, so the pivot is
     * updated and the orders are left alone (`orders_assigned` comes back 0 and
     * the caller says so).
     *
     * Orders already locked (`partner_locked_at`, i.e. the partner marked them
     * printed) are skipped — same rule as setPartnerRevenue. Revenue is NOT
     * touched here; that stays an explicit step.
     *
     * Removing a partner does NOT clear `orders.partner_id`: an order can
     * belong to two gangs, so clearing from one would silently strip an
     * assignment made by the other. Use POST /orders/bulk-assign-partner with
     * partner_id=null to unassign deliberately.
     */
    public function setPartners(Request $request, Gangsheet $gangsheet)
    {
        $request->validate([
            'user_ids' => 'present|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $userIds = array_values(array_unique(array_map('intval', $request->user_ids ?? [])));
        $gangsheet->partners()->sync($userIds);

        $assigned = 0;
        $cleared = 0;
        $skippedLocked = 0;
        $orderIds = $gangsheet->order_ids ?? [];

        if (! empty($orderIds)) {
            if (count($userIds) === 1) {
                $skippedLocked = Order::whereIn('id', $orderIds)
                    ->whereNotNull('partner_locked_at')
                    ->count();
                $assigned = Order::whereIn('id', $orderIds)
                    ->whereNull('partner_locked_at')
                    ->update(['partner_id' => $userIds[0]]);
            } elseif (count($userIds) === 0) {
                // Taking the gang away from everyone has to take the orders
                // back too. Clearing only the pivot would leave partner_id
                // stamped, so the partner would keep earning on orders they no
                // longer have — and would still see them, since the read paths
                // union partner_id with the pivot.
                //
                // partner_revenue goes with it: an amount owed to nobody is a
                // number nobody owns. Locked orders are settled, so they keep
                // both their partner and their amount.
                $base = Order::whereIn('id', $orderIds)->whereNotNull('partner_id');
                $skippedLocked = (clone $base)->whereNotNull('partner_locked_at')->count();
                $cleared = (clone $base)->whereNull('partner_locked_at')
                    ->update(['partner_id' => null, 'partner_revenue' => null]);
            }
        }

        return response()->json([
            'message' => count($userIds) === 0
                ? ($cleared > 0
                    ? "Đã gỡ partner khỏi gang · trả lại {$cleared} đơn (xoá luôn số tiền đã tính)"
                        .($skippedLocked ? " · giữ nguyên {$skippedLocked} đơn đã chốt" : '')
                    : 'Đã gỡ partner khỏi gang')
                : 'Partners updated',
            'partner_ids' => $gangsheet->partners()->pluck('users.id'),
            'orders_assigned' => $assigned,
            'orders_cleared' => $cleared,
            'orders_skipped_locked' => $skippedLocked,
        ]);
    }

    /**
     * Admin assigns ONE partner to this gangsheet's orders. Revenue can be:
     *   - explicitly provided per order (items[].revenue)
     *   - auto-calculated from product_variant_partner_prices when
     *     items[].revenue is null AND auto_price=true (default)
     *
     * Auto-calc sums partner_price × quantity for each order item, using
     * the partner's tier_id. Orders already locked are skipped.
     */
    public function setPartnerRevenue(Request $request, Gangsheet $gangsheet)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'items' => 'present|array',
            'items.*.order_id' => 'required|integer',
            'items.*.revenue' => 'nullable|numeric|min:0',
            'auto_price' => 'sometimes|boolean',
        ]);

        $autoPrice = $data['auto_price'] ?? true;

        $partner = User::whereKey($data['user_id'])
            ->whereHas('role', fn ($q) => $q->where('slug', 'partner'))
            ->first();
        if (! $partner) {
            return response()->json(['message' => 'User is not a partner'], 422);
        }

        $gangOrderIds = array_flip(array_map('intval', $gangsheet->order_ids ?? []));
        $updated = 0;
        $skippedLocked = [];
        $autoCalcDetails = [];

        DB::transaction(function () use ($data, $partner, $gangsheet, $gangOrderIds, $autoPrice, &$updated, &$skippedLocked, &$autoCalcDetails) {
            // Attach the partner to the gangsheet (keep any others already on it).
            $gangsheet->partners()->syncWithoutDetaching([$partner->id]);

            foreach ($data['items'] as $item) {
                $oid = (int) $item['order_id'];
                if (! isset($gangOrderIds[$oid])) {
                    continue;
                }
                $order = Order::find($oid);
                if (! $order) {
                    continue;
                }
                if ($order->partner_locked_at !== null) {
                    $skippedLocked[] = $order->system_id;

                    continue;
                }

                $revenue = $item['revenue'] !== null
                    ? round((float) $item['revenue'], 2)
                    : null;

                // Auto-calculate from partner prices when revenue not provided.
                if ($revenue === null && $autoPrice && $partner->partner_price_list_id) {
                    $revenue = $this->calcPartnerRevenue($order, $partner->partner_price_list_id);
                    if ($revenue !== null) {
                        $autoCalcDetails[] = ['order_id' => $oid, 'revenue' => $revenue];
                    }
                }

                $order->update([
                    'partner_id' => $partner->id,
                    'partner_revenue' => $revenue,
                ]);
                $updated++;
            }
        });

        return response()->json([
            'message' => "Đã gán doanh thu cho {$updated} đơn"
                .(count($skippedLocked) ? ' · bỏ qua '.count($skippedLocked).' đơn đã khoá' : '')
                .(count($autoCalcDetails) ? ' · tự tính '.count($autoCalcDetails).' đơn từ bảng giá partner' : ''),
            'updated' => $updated,
            'skipped_locked' => $skippedLocked,
            'auto_calculated' => $autoCalcDetails,
        ]);
    }

    /**
     * Calculate partner revenue for an order from partner_price columns.
     *
     * For each item:
     *   variant partner cost = SUM(partner_price) across all 5 keys for that variant × tier
     *   accessory partner cost = SUM(partner_price) from the item's accessory prices
     *   item total = (variant_partner_cost + accessory_partner_cost) × quantity
     *
     * Returns null if no partner_price is set for any item.
     */
    /**
     * What this order is worth to a partner, from their rate card.
     *
     * Sums only the keys the card actually prices: a key with no row is not
     * paid, which is how "the partner earns the print fees but not shipping"
     * is expressed. There is no separate list of enabled keys to fall out of
     * step with the prices themselves.
     *
     * Returns null when the card prices nothing on this order, so the caller
     * can tell "no rate configured" apart from a genuine zero.
     */
    private function calcPartnerRevenue(Order $order, int $listId): ?float
    {
        $order->loadMissing(['items.accessoryPrices']);

        $variantIds = $order->items->pluck('product_variant_id')->filter()->unique()->values()->all();
        if (empty($variantIds)) {
            return null;
        }

        // variantId => sum of the priced keys on this card
        $variantPrices = PartnerVariantPrice::where('partner_price_list_id', $listId)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->sum('price'));

        $accessoryPriceIds = $order->items
            ->flatMap(fn ($i) => $i->accessoryPrices->pluck('id'))
            ->unique()->values()->all();
        $accessoryPrices = empty($accessoryPriceIds)
            ? collect()
            : PartnerAccessoryPrice::where('partner_price_list_id', $listId)
                ->whereIn('accessory_price_id', $accessoryPriceIds)
                ->pluck('price', 'accessory_price_id');

        $total = 0;
        $hasPrice = false;

        foreach ($order->items as $item) {
            $qty = max(1, (int) $item->quantity);
            $itemPartner = 0;

            if ($item->product_variant_id && $variantPrices->has($item->product_variant_id)) {
                $itemPartner += (float) $variantPrices[$item->product_variant_id];
                $hasPrice = true;
            }

            foreach ($item->accessoryPrices as $ap) {
                if ($accessoryPrices->has($ap->id)) {
                    $itemPartner += (float) $accessoryPrices[$ap->id];
                    $hasPrice = true;
                }
            }

            $total += $itemPartner * $qty;
        }

        return $hasPrice ? round($total, 2) : null;
    }

    public function show(Gangsheet $gangsheet)
    {
        $gangsheet->load('creator:id,name', 'partners:id,name');
        $orderIds = $gangsheet->order_ids ?? [];
        $orders = empty($orderIds)
            ? collect()
            : Order::whereIn('id', $orderIds)
                ->select('id', 'system_id', 'ref_id', 'user_id', 'status', 'total_cost',
                    'partner_id', 'partner_revenue', 'partner_locked_at')
                ->with('user:id,name', 'partner:id,name')
                ->orderBy('id', 'asc')
                ->get();

        return response()->json([
            'gangsheet' => $gangsheet,
            'orders' => $orders,
        ]);
    }

    /**
     * The orders behind one gangsheet, eager-loaded the way the desktop
     * builder needs them — same shape as lookupOrders / pendingOrders.
     *
     * Feeds the Manage tab's "Re-gang": rebuilding the sheet needs the item
     * metas and variants, which show() deliberately does not carry (it feeds a
     * read-only detail panel). includeProduced is implied — every meta on an
     * existing gang is already produced.
     */
    public function rebuildSource(Gangsheet $gangsheet)
    {
        $orderIds = $gangsheet->order_ids ?? [];

        $orders = empty($orderIds) ? collect() : Order::with([
            'items.metas' => fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX),
            'items.productVariant.product:id,name,line_id,order_type',
            'items.material:id,name',
            'items.accessoryPrice.accessory:id,name,product_id,gangsheet_split',
            'items.accessoryPrices.accessory:id,name,product_id,gangsheet_split',
        ])->whereIn('id', $orderIds)->orderBy('id', 'asc')->get();

        return response()->json([
            'gangsheet' => $gangsheet,
            'orders' => $orders,
            // Ids in order_ids whose order has since been deleted. Rebuilding
            // without them would silently produce a smaller sheet, so the
            // caller is told rather than left to compare counts.
            'missing' => array_values(array_diff(
                array_map('intval', $orderIds),
                $orders->pluck('id')->map(fn ($i) => (int) $i)->all()
            )),
        ]);
    }

    /**
     * Point an existing gangsheet at freshly built files.
     *
     * The record keeps its identity — order_ids, meta_ids, partner assignment,
     * the downloaded mark — and only the artefacts move. That is the whole
     * point over delete-and-rebuild: a re-gang to fix the files should not
     * detach the partner it was handed to or forget it had been printed.
     *
     * filename comes along because the builder derives it from the current
     * date and counts, and the new PDFs are uploaded under that new name; a
     * record still pointing at the old name would be a dead link.
     */
    public function replaceFiles(Request $request, Gangsheet $gangsheet)
    {
        $data = $request->validate([
            'filename' => 'required|string',
            'file_url' => 'required|url',
            'pdf_urls' => 'nullable|array',
            'pdf_urls.*' => 'url',
            'png_urls' => 'nullable|array',
            'png_urls.*' => 'url',
        ]);

        $gangsheet->update([
            'filename' => $data['filename'],
            'file_url' => $data['file_url'],
            'pdf_urls' => $data['pdf_urls'] ?? null,
            'png_urls' => $data['png_urls'] ?? null,
        ]);

        return response()->json([
            'message' => 'Đã thay file cho '.$data['filename'],
            'gangsheet' => $gangsheet->fresh()->load('creator:id,name', 'partners:id,name'),
        ]);
    }

    /**
     * Orders ready to be ganged: have at least one *_qr meta whose own
     * production=0, are themselves production=0, and have NOT been shipped yet
     * (no point producing prints for a shipped order).
     */
    public function pendingOrders(Request $request)
    {
        $query = Order::with([
            'items.metas' => fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX),
            'items.productVariant.product:id,name,line_id,order_type',
            'items.material:id,name',
            // Eager-load both legacy single-accessory and multi-pivot so the
            // Compose tab can filter "Scratch Card" by accessory.id, not name.
            'items.accessoryPrice.accessory:id,name,product_id,gangsheet_split',
            'items.accessoryPrices.accessory:id,name,product_id,gangsheet_split',
            'partner:id,name',
        ])
            ->where('production', false)
            ->whereNull('gangsheet_group_id')   // not already pulled into an automation group
            ->where('status', '!=', Order::STATUS_SHIPPED)
            ->whereHas('items.metas', fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX)->where('production', false))
            ->orderBy('id', 'asc');

        // Channel scope — the FPT composer must never pull normal orders and
        // vice-versa. No param = 'normal' (what the existing screen sends).
        $query->ofSource($request->input('source'));

        if ($request->filled('line_id')) {
            $query->whereHas('items.productVariant.product', fn ($q) => $q->where('line_id', $request->line_id));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        // Optional fulfill_status (= order.status) multi-filter (manual chips).
        if ($request->filled('statuses')) {
            $query->whereIn('status', array_map('intval', (array) $request->statuses));
        }
        // Per-user assign rules (shared with group assign): compose pending only
        // shows orders allowed by each user's status rule, when configured.
        $rules = (array) AppSetting::getJson('gangsheet_assign_rules', []);
        if (! empty($rules)) {
            GangsheetGroupController::applyAssignRules($query, $rules);
        }

        return response()->json($query->paginate($request->get('per_page', 100)));
    }

    /**
     * Look up orders by an explicit list of system_ids — used by the Find /
     * Re-gang tab to rebuild a gangsheet for orders that may already have
     * production=true.
     */
    public function lookupOrders(Request $request)
    {
        $request->validate([
            'system_ids' => 'required|array|min:1',
            'system_ids.*' => 'string',
        ]);

        $ids = array_values(array_unique(array_map('trim', $request->system_ids)));

        $orders = Order::with([
            'items.metas' => fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX),
            'items.productVariant.product:id,name,line_id,order_type',
            'items.material:id,name',
            // Eager-load both legacy single-accessory and multi-pivot so the
            // Compose tab can filter "Scratch Card" by accessory.id, not name.
            'items.accessoryPrice.accessory:id,name,product_id,gangsheet_split',
            'items.accessoryPrices.accessory:id,name,product_id,gangsheet_split',
        ])
            ->whereIn('system_id', $ids)
            ->orderBy('id', 'asc')
            ->get();

        $found = $orders->pluck('system_id')->all();
        $missing = array_values(array_diff($ids, $found));

        return response()->json([
            'orders' => $orders,
            'found' => $found,
            'missing' => $missing,
        ]);
    }

    /**
     * Hand the desktop app the B2 credentials so it can upload directly. Used
     * by gangsheet builder (admin) and OrderCreate upload buttons (any auth
     * user, incl. sellers). Folder is date-scoped; per-feature subfolders are
     * appended client-side.
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
     * Record a gang sheet that the desktop app has already uploaded directly
     * to B2 (via the presigned URL above). No file upload here — the request
     * body is plain JSON metadata.
     */
    public function store(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'file_url' => 'required|url',
            'png_urls' => 'nullable|array',
            'png_urls.*' => 'url',
            // One PDF per page (card-skin gangs). file_url stays the first page.
            'pdf_urls' => 'nullable|array',
            'pdf_urls.*' => 'url',
            'line_id' => 'nullable|string|max:16',
            'source' => 'nullable|string|in:'.implode(',', Order::SOURCES),
            'page_format' => 'nullable|string|max:16',
            'first_system_id' => 'nullable|string',
            'last_system_id' => 'nullable|string',
            'orders_count' => 'required|integer|min:1',
            'metas_count' => 'required|integer|min:1',
            'order_ids' => 'required',
            'meta_ids' => 'required',
        ]);

        $orderIds = $this->decodeIds($request->input('order_ids'));
        $metaIds = $this->decodeIds($request->input('meta_ids'));

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
            ], $request->user()?->id);
        } catch (\Throwable $e) {
            \Log::error('[gangsheet] DB save failed: '.$e->getMessage(), ['filename' => $request->filename]);

            return response()->json([
                'message' => 'Failed to record gang sheet in database: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Gangsheet saved',
            'gangsheet' => $gangsheet->load('creator:id,name'),
        ], 201);
    }

    /**
     * Attach the per-page PNG exports to a gang that was built before (or
     * without) them. The desktop app rasterises the stored PDF, uploads the
     * pages to B2 and posts the resulting URLs here. Already-exported gangs
     * are returned untouched so the caller can just reuse the existing files.
     */
    public function updatePngUrls(Request $request, Gangsheet $gangsheet)
    {
        $request->validate([
            'png_urls' => 'required|array|min:1',
            'png_urls.*' => 'url',
            'force' => 'nullable|boolean',
        ]);

        // force=true is the "xuất lại" path: the caller rebuilt the pages on
        // purpose (e.g. after the card-skin sheets became transparent) and
        // wants the record to point at the new files.
        if (! empty($gangsheet->png_urls) && ! $request->boolean('force')) {
            return response()->json([
                'message' => 'Gangsheet already has PNG exports',
                'gangsheet' => $gangsheet,
                'reused' => true,
            ]);
        }

        $gangsheet->update(['png_urls' => array_values($request->input('png_urls'))]);

        return response()->json([
            'message' => 'PNG exports saved',
            'gangsheet' => $gangsheet->fresh(),
            'reused' => false,
        ]);
    }

    public function destroy(Gangsheet $gangsheet)
    {
        // Soft-delete: keep the orders/metas marked production=true.
        $gangsheet->delete();

        return response()->json(['message' => 'Gangsheet deleted']);
    }

    /**
     * Bulk-delete gangsheets selected from the Manage tab. Same semantics as
     * single destroy — orders/metas keep their production=true flags so we
     * don't accidentally re-gang the same prints.
     */
    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'gangsheet_ids' => 'required|array|min:1',
            'gangsheet_ids.*' => 'integer|exists:gangsheets,id',
        ]);

        $deleted = Gangsheet::whereIn('id', $request->gangsheet_ids)->delete();

        return response()->json([
            'message' => "Deleted {$deleted} gangsheet(s)",
            'count' => $deleted,
        ]);
    }

    private function decodeIds($input): array
    {
        if (is_array($input)) {
            return array_values(array_unique(array_map('intval', $input)));
        }
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                return array_values(array_unique(array_map('intval', $decoded)));
            }

            return array_values(array_unique(array_map('intval', explode(',', $input))));
        }

        return [];
    }
}
