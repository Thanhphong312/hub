<?php

namespace App\Http\Controllers;

use App\Models\Gangsheet;
use App\Models\GangsheetLabel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GangsheetController extends Controller
{
    private const QR_KEY_REGEX = '_qr(_[0-9]+)?$';

    public function index(Request $request)
    {
        $query = Gangsheet::with('creator:id,name', 'partners:id,name')->orderBy('id', 'desc');

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('line_id')) {
            $query->where('line_id', $request->line_id);
        }
        if ($request->filled('page_format')) {
            // Legacy gangs (null format) were Letter 11×8.5 → group them there.
            if ($request->page_format === 'letter') {
                $query->where(fn ($q) => $q->where('page_format', 'letter')->orWhereNull('page_format'));
            } else {
                $query->where('page_format', $request->page_format);
            }
        }

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    /** Users with the 'partner' role — for the admin "assign to partner" picker. */
    public function partnerUsers()
    {
        return response()->json(
            \App\Models\User::whereHas('role', fn ($q) => $q->where('slug', 'partner'))
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get()
        );
    }

    /** Admin sets which partner users a gangsheet is assigned to. */
    public function setPartners(Request $request, Gangsheet $gangsheet)
    {
        $request->validate([
            'user_ids'   => 'present|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);
        $gangsheet->partners()->sync($request->user_ids ?? []);
        return response()->json([
            'message'     => 'Partners updated',
            'partner_ids' => $gangsheet->partners()->pluck('users.id'),
        ]);
    }

    public function show(Gangsheet $gangsheet)
    {
        $gangsheet->load('creator:id,name', 'partners:id,name');
        $orderIds = $gangsheet->order_ids ?? [];
        $orders = empty($orderIds)
            ? collect()
            : Order::whereIn('id', $orderIds)
                ->select('id', 'system_id', 'ref_id', 'user_id', 'status', 'total_cost')
                ->with('user:id,name')
                ->orderBy('id', 'asc')
                ->get();

        return response()->json([
            'gangsheet' => $gangsheet,
            'orders' => $orders,
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
            'items.productVariant.product:id,name,line_id',
            'items.material:id,name',
            // Eager-load both legacy single-accessory and multi-pivot so the
            // Compose tab can filter "Scratch Card" by accessory.id, not name.
            'items.accessoryPrice.accessory:id,name,product_id,gangsheet_split',
            'items.accessoryPrices.accessory:id,name,product_id,gangsheet_split',
        ])
            ->where('production', false)
            ->whereNull('gangsheet_group_id')   // not already pulled into an automation group
            ->where('status', '!=', Order::STATUS_SHIPPED)
            ->whereHas('items.metas', fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX)->where('production', false))
            ->orderBy('id', 'asc');

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
        $rules = (array) \App\Models\AppSetting::getJson('gangsheet_assign_rules', []);
        if (!empty($rules)) {
            \App\Http\Controllers\GangsheetGroupController::applyAssignRules($query, $rules);
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
            'items.productVariant.product:id,name,line_id',
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
            'folder' => 'gangsheet/' . now()->format('Y-m-d'),
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
            'line_id' => 'nullable|string|max:16',
            'page_format' => 'nullable|string|max:16',
            'first_system_id' => 'nullable|string',
            'last_system_id' => 'nullable|string',
            'orders_count' => 'required|integer|min:1',
            'metas_count' => 'required|integer|min:1',
            'order_ids' => 'required',
            'meta_ids' => 'required',
        ]);

        $orderIds = $this->decodeIds($request->input('order_ids'));
        $metaIds  = $this->decodeIds($request->input('meta_ids'));

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
            ], $request->user()?->id);
        } catch (\Throwable $e) {
            \Log::error('[gangsheet] DB save failed: ' . $e->getMessage(), ['filename' => $request->filename]);
            return response()->json([
                'message' => 'Failed to record gang sheet in database: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Gangsheet saved',
            'gangsheet' => $gangsheet->load('creator:id,name'),
        ], 201);
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
            if (is_array($decoded)) return array_values(array_unique(array_map('intval', $decoded)));
            return array_values(array_unique(array_map('intval', explode(',', $input))));
        }
        return [];
    }
}
