<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Gangsheet;
use App\Models\GangsheetGroup;
use App\Models\GangsheetLabel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMeta;
use App\Services\GangsheetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Automation gangsheet groups. See doc/automation_gangsheet.md.
 *
 * Flow: app cron calls assign() hourly to pull pending orders into OPEN groups
 * (one bucket per line|side|accessory). To turn groups into gangsheets, the app
 * calls claim() (atomic OPEN→CLOSING) for the groups it will build, builds the
 * PDFs in Electron, then finalize()s each. remove-design detaches orders (back
 * to pending) and lets the client regen the group keeping the same seq.
 *
 * IMPORTANT: there is NO server scheduler. Every periodic action (assign,
 * release-stale, auto-close) is driven by the desktop app's converter jobs.
 */
class GangsheetGroupController extends Controller
{
    private const QR_KEY_REGEX = '_qr(_[0-9]+)?$';
    private const STALE_MINUTES = 10;   // a CLOSING group older than this auto-reverts to OPEN

    // Eager loads matching the Compose tab's expectations so the client can
    // feed claimed orders straight into buildGangsheetForChunk().
    private function orderEagerLoads(): array
    {
        return [
            'items.metas' => fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX),
            'items.productVariant.product:id,name,line_id',
            'items.material:id,name',
            'items.accessoryPrice.accessory:id,name,product_id,gangsheet_split',
            'items.accessoryPrices.accessory:id,name,product_id,gangsheet_split',
        ];
    }

    // ── Assign: pull pending orders into OPEN groups (app cron, hourly) ──────
    public function assign(Request $request)
    {
        $this->releaseStaleInternal();

        $groupSize = max(1, (int) (AppSetting::getJson('gangsheet_group_size', ['size' => 10])['size'] ?? 10));

        // fulfill_status (= order.status) filter: from the request, else the
        // saved setting. Empty = every non-shipped order.
        $statuses = $request->filled('statuses')
            ? array_map('intval', (array) $request->statuses)
            : array_map('intval', (array) AppSetting::getJson('gangsheet_assign_statuses', []));

        $summary = DB::transaction(function () use ($groupSize, $statuses) {
            $pending = Order::with($this->orderEagerLoads())
                ->where('production', false)
                ->whereNull('gangsheet_group_id')
                ->where('status', '!=', Order::STATUS_SHIPPED)
                ->when(!empty($statuses), fn ($q) => $q->whereIn('status', $statuses))
                ->whereHas('items.metas', fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX)->where('production', false))
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // Bucket the pending orders. Every bucket forms a group regardless of
            // count — at a close hook we want everything grouped and ganged.
            $byBucket = [];   // bucket_key => ['dims'=>[lineId,side,accId,matId], 'orders'=>[Order...]]
            foreach ($pending as $order) {
                [$lineId, $side, $accId, $matId] = $this->computeBucket($order);
                $key = GangsheetGroup::bucketKey($lineId, $side, $accId, $matId);
                if (!isset($byBucket[$key])) {
                    $byBucket[$key] = ['dims' => [$lineId, $side, $accId, $matId], 'orders' => []];
                }
                $byBucket[$key]['orders'][] = $order;
            }

            $day = GangsheetGroup::productionDay();
            $assigned = 0;
            $touched = [];

            foreach ($byBucket as $key => $b) {
                $group = GangsheetGroup::where('bucket_key', $key)
                    ->where('status', GangsheetGroup::STATUS_OPEN)
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->first();

                [$lineId, $side, $accId, $matId] = $b['dims'];
                foreach ($b['orders'] as $order) {
                    if (!$group || count($group->order_ids ?? []) >= $groupSize) {
                        $group = $this->createGroup($day, $lineId, $side, $accId, $matId, $key);
                    }
                    $ids = $group->order_ids ?? [];
                    $ids[] = $order->id;
                    $group->order_ids = $ids;
                    $group->save();

                    $order->gangsheet_group_id = $group->id;
                    $order->saveQuietly();

                    $touched[$group->id] = true;
                    $assigned++;
                }
            }

            return ['assigned' => $assigned, 'groups_touched' => count($touched), 'production_day' => $day, 'group_size' => $groupSize];
        });

        return response()->json($summary);
    }

    private function createGroup(string $day, ?string $lineId, string $side, ?int $accId, ?int $matId, string $bucket): GangsheetGroup
    {
        // seq resets each production day (08:00 VN); max+1 within the day.
        $seq = (int) GangsheetGroup::where('production_day', $day)->max('seq') + 1;
        return GangsheetGroup::create([
            'seq'            => $seq,
            'production_day' => $day,
            'line_id'        => $lineId,
            'side_type'      => $side,
            'accessory_id'   => $accId,
            'material_id'    => $matId,
            'bucket_key'     => $bucket,
            'status'         => GangsheetGroup::STATUS_OPEN,
            'order_ids'      => [],
        ]);
    }

    /**
     * One order → one bucket (decision: order-level, see doc §9.4/§12):
     *   side_type    = 'two' if it has both a front_qr and a back_qr (un-produced),
     *                  else 'one'.
     *   accessory_id = smallest id among gangsheet_split=true accessories, else null.
     *   line_id      = dominant product line_id across the order's items.
     *   material_id  = the FIRST item's material (first item that has one). For
     *                  a multi-material order we only look at the first item —
     *                  no "mixed" split.
     *
     * @return array{0:?string,1:string,2:?int,3:?int}
     *         [lineId, sideType, accessoryId, materialId]
     */
    private function computeBucket(Order $order): array
    {
        $hasFront = false;
        $hasBack  = false;
        $lineCounts = [];
        $accIds = [];
        $matId = null;

        foreach ($order->items as $it) {
            foreach ($it->metas as $m) {
                if ($m->production) continue;
                if (preg_match('/^front_qr(_\d+)?$/', $m->key)) $hasFront = true;
                if (preg_match('/^back_qr(_\d+)?$/', $m->key))  $hasBack  = true;
            }
            $li = $it->productVariant?->product?->line_id;
            if ($li) $lineCounts[$li] = ($lineCounts[$li] ?? 0) + 1;

            $collect = function ($accessory) use (&$accIds) {
                if ($accessory && $accessory->gangsheet_split) $accIds[] = (int) $accessory->id;
            };
            foreach ($it->accessoryPrices as $ap) $collect($ap->accessory);
            $collect($it->accessoryPrice?->accessory);

            // First item with a material decides the order's material bucket.
            if ($matId === null && $it->material_id) $matId = (int) $it->material_id;
        }

        $side = ($hasFront && $hasBack) ? 'two' : 'one';

        $lineId = null;
        if (!empty($lineCounts)) {
            arsort($lineCounts);
            $lineId = array_key_first($lineCounts);
        }

        $accId = empty($accIds) ? null : min($accIds);

        return [$lineId, $side, $accId, $matId];
    }

    // ── List / detail (UI) ───────────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = GangsheetGroup::with('gangsheet:id,filename,file_url')
            ->orderBy('id', 'desc');

        if ($request->filled('status'))  $query->where('status', $request->status);
        if ($request->filled('line_id')) $query->where('line_id', $request->line_id);
        if ($request->filled('production_day')) $query->where('production_day', $request->production_day);
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        return response()->json($query->paginate($request->get('per_page', 50)));
    }

    public function show(GangsheetGroup $group)
    {
        return response()->json($this->groupWithOrders($group));
    }

    // ── Claim: OPEN → CLOSING (atomic), return build payload ──────────────────
    public function claim(Request $request)
    {
        $request->validate([
            'group_ids'   => 'sometimes|array',
            'group_ids.*' => 'integer',
        ]);

        $this->releaseStaleInternal();

        // Both manual (explicit group_ids) and auto (no ids → all open) close
        // groups regardless of count — at a hook we gang whatever is there.
        $claimedIds = DB::transaction(function () use ($request) {
            $q = GangsheetGroup::where('status', GangsheetGroup::STATUS_OPEN);
            if ($request->filled('group_ids')) {
                $q->whereIn('id', $request->group_ids);
            }
            $rows = $q->lockForUpdate()->get()
                ->filter(fn ($g) => count($g->order_ids ?? []) > 0)   // skip empty groups
                ->values();

            foreach ($rows as $g) {
                $g->status = GangsheetGroup::STATUS_CLOSING;
                $g->closing_at = now();
                $g->claimed_by_user_id = $request->user()?->id;
                $g->save();
            }
            return $rows->pluck('id')->all();
        });

        $groups = GangsheetGroup::whereIn('id', $claimedIds)->orderBy('id')->get()
            ->map(fn ($g) => $this->groupWithOrders($g));

        return response()->json(['groups' => $groups]);
    }

    // ── Finalize: CLOSING → GENERATED (record the built gangsheet) ────────────
    public function finalize(Request $request, GangsheetGroup $group, GangsheetService $svc)
    {
        $request->validate([
            'filename'        => 'required|string',
            'file_url'        => 'required|url',
            'line_id'         => 'nullable|string|max:16',
            'page_format'     => 'nullable|string|max:16',
            'first_system_id' => 'nullable|string',
            'last_system_id'  => 'nullable|string',
            'orders_count'    => 'required|integer|min:1',
            'metas_count'     => 'required|integer|min:1',
            'order_ids'       => 'required|array|min:1',
            'meta_ids'        => 'required|array|min:1',
        ]);

        if ($group->status !== GangsheetGroup::STATUS_CLOSING) {
            return response()->json([
                'message' => "Group #{$group->id} is '{$group->status}', expected 'closing'. Re-claim before finalizing.",
            ], 409);
        }

        $gs = $svc->record($request->only([
            'filename', 'file_url', 'line_id', 'page_format', 'first_system_id', 'last_system_id',
            'orders_count', 'metas_count', 'order_ids', 'meta_ids',
        ]), $request->user()?->id);

        $group->gangsheet_id = $gs->id;
        $group->status = GangsheetGroup::STATUS_GENERATED;
        $group->generated_at = now();
        $group->closing_at = null;
        $group->claimed_by_user_id = null;
        $group->save();

        return response()->json([
            'group'     => $group->fresh('gangsheet'),
            'gangsheet' => $gs->load('creator:id,name'),
        ], 201);
    }

    // ── Remove design(s): detach orders → back to pending, drop old gang ──────
    public function removeDesign(Request $request, GangsheetGroup $group)
    {
        $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer',
        ]);
        $removeIds = array_map('intval', $request->order_ids);

        $result = DB::transaction(function () use ($group, $removeIds) {
            $orders = Order::whereIn('id', $removeIds)
                ->where('gangsheet_group_id', $group->id)
                ->get();
            $actualIds = $orders->pluck('id')->all();

            if (!empty($actualIds)) {
                // Back to pending: order + its _qr metas production=false, detach.
                Order::whereIn('id', $actualIds)->update([
                    'production' => false,
                    'gangsheet_group_id' => null,
                ]);
                $itemIds = OrderItem::whereIn('order_id', $actualIds)->pluck('id');
                OrderItemMeta::whereIn('order_item_id', $itemIds)
                    ->where('key', 'regexp', self::QR_KEY_REGEX)
                    ->update(['production' => false]);
            }

            $remaining = array_values(array_diff($group->order_ids ?? [], $actualIds));
            $group->order_ids = $remaining;

            // Drop the old gangsheet record + its label (the PDF on B2 is deleted
            // client-side via s3Delete; we return its URL for that).
            $deletedFileUrl = null;
            if ($group->gangsheet_id) {
                $old = Gangsheet::find($group->gangsheet_id);
                if ($old) {
                    $deletedFileUrl = $old->file_url;
                    GangsheetLabel::where('gangsheet_id', $old->id)->delete();
                    $old->delete();
                }
            }
            $group->gangsheet_id = null;
            $group->generated_at = null;

            if (empty($remaining)) {
                // Nothing left → drop the group entirely (seq is simply skipped).
                $group->delete();
                return ['group' => null, 'deleted_file_url' => $deletedFileUrl, 'detached' => $actualIds];
            }

            // Keep seq; reopen so the client can re-claim + regen with same number.
            $group->status = GangsheetGroup::STATUS_OPEN;
            $group->claimed_by_user_id = null;
            $group->closing_at = null;
            $group->save();

            return ['group' => $this->groupWithOrders($group->fresh()), 'deleted_file_url' => $deletedFileUrl, 'detached' => $actualIds];
        });

        return response()->json($result);
    }

    // ── Delete a single group: detach all its orders → back to pending; drop
    //    the gangsheet + label if it was generated (B2 PDF deleted client-side
    //    via the returned url). ─────────────────────────────────────────────
    public function destroy(GangsheetGroup $group)
    {
        $deletedFileUrl = DB::transaction(function () use ($group) {
            $orderIds = $group->order_ids ?? [];
            if (!empty($orderIds)) {
                Order::whereIn('id', $orderIds)
                    ->where('gangsheet_group_id', $group->id)
                    ->update(['production' => false, 'gangsheet_group_id' => null]);
                $itemIds = OrderItem::whereIn('order_id', $orderIds)->pluck('id');
                OrderItemMeta::whereIn('order_item_id', $itemIds)
                    ->where('key', 'regexp', self::QR_KEY_REGEX)
                    ->update(['production' => false]);
            }

            $url = null;
            if ($group->gangsheet_id) {
                $old = Gangsheet::find($group->gangsheet_id);
                if ($old) {
                    $url = $old->file_url;
                    GangsheetLabel::where('gangsheet_id', $old->id)->delete();
                    $old->delete();
                }
            }
            $group->delete();
            return $url;
        });

        return response()->json(['message' => 'Group deleted', 'deleted_file_url' => $deletedFileUrl]);
    }

    // ── Delete ALL open groups (detach orders → pending). Used to rebuild after
    //    changing group size. Open groups have no gangsheet, so no B2 cleanup. ─
    public function deleteOpen()
    {
        $count = DB::transaction(function () {
            $ids = GangsheetGroup::where('status', GangsheetGroup::STATUS_OPEN)->pluck('id');
            if ($ids->isEmpty()) return 0;
            Order::whereIn('gangsheet_group_id', $ids)
                ->update(['production' => false, 'gangsheet_group_id' => null]);
            return GangsheetGroup::whereIn('id', $ids)->delete();
        });

        return response()->json(['message' => "Deleted {$count} open group(s)", 'count' => $count]);
    }

    // ── Stale release (app calls this; also called inline before assign/claim) ─
    public function releaseStale()
    {
        $n = $this->releaseStaleInternal();
        return response()->json(['released' => $n]);
    }

    private function releaseStaleInternal(): int
    {
        return GangsheetGroup::where('status', GangsheetGroup::STATUS_CLOSING)
            ->where('closing_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status' => GangsheetGroup::STATUS_OPEN,
                'claimed_by_user_id' => null,
                'closing_at' => null,
            ]);
    }

    // ── Automation config (group size + auto-close hooks) ─────────────────────
    public function getAutomationConfig()
    {
        $size  = AppSetting::getJson('gangsheet_group_size', ['size' => 10]);
        $auto  = AppSetting::getJson('gangsheet_auto_close', ['enabled' => false, 'hooks' => []]);
        $marks = AppSetting::getJson('gangsheet_close_marks', ['date' => null, 'fired' => []]);

        return response()->json([
            'group_size'      => (int) ($size['size'] ?? 10),
            'assign_statuses' => array_values(array_map('intval', (array) AppSetting::getJson('gangsheet_assign_statuses', []))),
            'auto_close'     => [
                'enabled' => (bool) ($auto['enabled'] ?? false),
                'hooks'   => array_values($auto['hooks'] ?? []),
            ],
            'production_day' => GangsheetGroup::productionDay(),
            'close_marks'    => $marks,
        ]);
    }

    public function updateAutomationConfig(Request $request)
    {
        $data = $request->validate([
            'group_size'           => 'sometimes|integer|min:1|max:1000',
            'assign_statuses'      => 'sometimes|array',
            'assign_statuses.*'    => 'integer|min:0|max:7',
            'auto_close'           => 'sometimes|array',
            'auto_close.enabled'   => 'nullable|boolean',
            'auto_close.hooks'     => 'nullable|array',
            'auto_close.hooks.*'   => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        if (isset($data['group_size'])) {
            AppSetting::setJson('gangsheet_group_size', ['size' => (int) $data['group_size']]);
        }
        if (isset($data['assign_statuses'])) {
            AppSetting::setJson('gangsheet_assign_statuses', array_values(array_map('intval', $data['assign_statuses'])));
        }
        if (isset($data['auto_close'])) {
            $cur = AppSetting::getJson('gangsheet_auto_close', ['enabled' => false, 'hooks' => []]);
            $merged = array_merge($cur, $data['auto_close']);
            if (isset($merged['hooks'])) {
                $merged['hooks'] = array_values(array_unique($merged['hooks']));
                sort($merged['hooks']);
            }
            AppSetting::setJson('gangsheet_auto_close', $merged);
        }

        return $this->getAutomationConfig();
    }

    /**
     * Atomically claim an auto-close hook for the current production day so
     * multiple open apps don't each auto-close the same time mark. Returns
     * was_new=false if another client already fired it today.
     */
    public function markFired(Request $request)
    {
        $data = $request->validate([
            'hook' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $day = GangsheetGroup::productionDay();
        $wasNew = DB::transaction(function () use ($data, $day) {
            $marks = AppSetting::getJson('gangsheet_close_marks', ['date' => null, 'fired' => []]);
            if (($marks['date'] ?? null) !== $day) {
                $marks = ['date' => $day, 'fired' => []];
            }
            if (in_array($data['hook'], $marks['fired'], true)) {
                return false;
            }
            $marks['fired'][] = $data['hook'];
            AppSetting::setJson('gangsheet_close_marks', $marks);
            return true;
        });

        return response()->json(['was_new' => $wasNew, 'hook' => $data['hook'], 'production_day' => $day]);
    }

    // Group + its member orders (build/detail payload).
    private function groupWithOrders(GangsheetGroup $group): array
    {
        $orders = Order::with($this->orderEagerLoads())
            ->where('gangsheet_group_id', $group->id)
            ->orderBy('id', 'asc')
            ->get();

        return [
            'group'  => $group->load('gangsheet:id,filename,file_url'),
            'orders' => $orders,
        ];
    }
}
