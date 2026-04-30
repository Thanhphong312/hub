<?php

namespace App\Http\Controllers;

use App\Models\Gangsheet;
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
        $query = Gangsheet::with('creator:id,name')->orderBy('id', 'desc');

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('line_id')) {
            $query->where('line_id', $request->line_id);
        }

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    public function show(Gangsheet $gangsheet)
    {
        $gangsheet->load('creator:id,name');
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
     * production=0, and are themselves production=0.
     */
    public function pendingOrders(Request $request)
    {
        $query = Order::with([
            'items.metas' => fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX),
            'items.productVariant.product:id,name,line_id',
        ])
            ->where('production', false)
            ->whereHas('items.metas', fn ($q) => $q->where('key', 'regexp', self::QR_KEY_REGEX)->where('production', false))
            ->orderBy('id', 'asc');

        if ($request->filled('line_id')) {
            $query->whereHas('items.productVariant.product', fn ($q) => $q->where('line_id', $request->line_id));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
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

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:51200', // up to 50MB
            'filename' => 'required|string',
            'line_id' => 'nullable|string|max:16',
            'first_system_id' => 'nullable|string',
            'last_system_id' => 'nullable|string',
            'orders_count' => 'required|integer|min:1',
            'metas_count' => 'required|integer|min:1',
            'order_ids' => 'required',
            'meta_ids' => 'required',
        ]);

        $orderIds = $this->decodeIds($request->input('order_ids'));
        $metaIds  = $this->decodeIds($request->input('meta_ids'));

        // Upload to B2 under gangsheet/<YYYY-MM-DD>/<filename>
        $folder = 'gangsheet/' . now()->format('Y-m-d');
        $path = $folder . '/' . $request->filename;
        $file = $request->file('file');

        Storage::disk('b2')->put($path, file_get_contents($file->getPathname()), 'public');
        $base = rtrim(config('filesystems.disks.b2.url') ?? '', '/');
        $url = $base . '/' . ltrim($path, '/');

        $gangsheet = DB::transaction(function () use ($request, $orderIds, $metaIds, $url) {
            $gs = Gangsheet::create([
                'created_by_user_id' => $request->user()?->id,
                'filename' => $request->filename,
                'file_url' => $url,
                'line_id' => $request->line_id,
                'first_system_id' => $request->first_system_id,
                'last_system_id' => $request->last_system_id,
                'orders_count' => (int) $request->orders_count,
                'metas_count' => (int) $request->metas_count,
                'order_ids' => $orderIds,
                'meta_ids' => $metaIds,
            ]);

            if (!empty($metaIds)) {
                OrderItemMeta::whereIn('id', $metaIds)->update(['production' => true]);
            }
            if (!empty($orderIds)) {
                Order::whereIn('id', $orderIds)->update(['production' => true]);
            }

            return $gs;
        });

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
