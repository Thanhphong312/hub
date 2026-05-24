<?php

namespace App\Http\Controllers;

use App\Models\GangsheetLabel;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GangsheetLabelController extends Controller
{
    public function index(Request $request)
    {
        $query = GangsheetLabel::with('creator:id,name')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('line_id')) {
            $query->where('line_id', $request->line_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    public function show(GangsheetLabel $gangsheetLabel)
    {
        $gangsheetLabel->load('creator:id,name', 'gangsheet:id,filename,file_url');
        $orderIds = $gangsheetLabel->order_ids ?? [];

        $orders = empty($orderIds)
            ? collect()
            : Order::whereIn('id', $orderIds)
                ->with([
                    'items:id,order_id,product_variant_id,quantity',
                    'user:id,name',
                ])
                ->makeVisible('convert_label')
                ->select('id', 'system_id', 'ref_id', 'user_id', 'status',
                         'shipping_label', 'convert_label', 'tracking_id', 'completed_time')
                ->orderBy('id', 'asc')
                ->get()
                ->each(fn ($o) => $o->makeVisible('convert_label'));

        // Recompute total qty live so the count stays accurate even after
        // items get added/removed post-gangsheet creation.
        $totalItemsQty = $orders->sum(fn ($o) => $o->items->sum('quantity'));

        return response()->json([
            'gangsheet_label' => $gangsheetLabel,
            'orders' => $orders,
            'total_items_qty' => $totalItemsQty,
            'scan_code' => $gangsheetLabel->scan_code,
        ]);
    }

    /**
     * Mark every order in the gangsheet label as shipped in one transaction.
     * Idempotent: orders already shipped are left untouched.
     */
    public function completeAll(Request $request, GangsheetLabel $gangsheetLabel)
    {
        $orderIds = $gangsheetLabel->order_ids ?? [];
        if (empty($orderIds)) {
            return response()->json(['message' => 'No orders to complete', 'count' => 0]);
        }

        return DB::transaction(function () use ($gangsheetLabel, $orderIds) {
            // Only flip orders that are not already shipped — keeps
            // completed_time on the original ship event.
            $toShip = Order::with('items')->whereIn('id', $orderIds)
                ->where('status', '!=', Order::STATUS_SHIPPED)
                ->get();
            $affected = 0;
            foreach ($toShip as $o) {
                $o->update(['status' => Order::STATUS_SHIPPED, 'completed_time' => now()]);
                $o->decrementStockForShipment();
                $affected++;
            }

            $gangsheetLabel->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return response()->json([
                'message' => "Marked {$affected} order(s) shipped",
                'count' => $affected,
                'gangsheet_label' => $gangsheetLabel->fresh(),
            ]);
        });
    }

    public function destroy(GangsheetLabel $gangsheetLabel)
    {
        $gangsheetLabel->delete();
        return response()->json(['message' => 'Gangsheet label deleted']);
    }

    /**
     * POST /api/gangsheet-labels/bulk-delete — xóa nhiều label cùng lúc.
     * Same semantics as single destroy: row bị xoá nhưng orders/metas giữ
     * production=true để tránh re-gang nhầm.
     */
    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'label_ids' => 'required|array|min:1',
            'label_ids.*' => 'integer|exists:gangsheet_labels,id',
        ]);

        $deleted = GangsheetLabel::whereIn('id', $request->label_ids)->delete();

        return response()->json([
            'message' => "Deleted {$deleted} gangsheet label(s)",
            'count' => $deleted,
        ]);
    }

    /**
     * POST /api/gangsheet-labels/merge — bundle N existing gangsheet labels
     * (where N >= 1) into 1 master record. Even with 1 selected this is
     * useful — produces a fresh PDF with all that label's orders + a single
     * scan QR pointing at the master.
     *
     * Child labels stay intact (for audit); the master gets order_ids =
     * union of all children, plus merged_from_label_ids = [child ids].
     *
     * The desktop app then builds a PDF (page per convert_label + 1 master
     * QR page at the end), uploads to B2, and calls updateFileUrl to save
     * the URL on the merged record.
     */
    public function merge(Request $request)
    {
        $request->validate([
            'label_ids' => 'required|array|min:1',
            'label_ids.*' => 'integer|exists:gangsheet_labels,id',
        ]);

        $labels = GangsheetLabel::whereIn('id', $request->label_ids)->get();
        if ($labels->isEmpty()) {
            return response()->json(['message' => 'No matching labels found'], 422);
        }

        // Union of all order ids across children, preserve order, dedupe.
        $orderIds = $labels
            ->flatMap(fn ($l) => $l->order_ids ?? [])
            ->unique()
            ->values()
            ->all();
        if (empty($orderIds)) {
            return response()->json(['message' => 'Selected labels have no orders'], 422);
        }

        $totalItemsQty = (int) \App\Models\OrderItem::whereIn('order_id', $orderIds)->sum('quantity');
        $dominantLineId = $labels->groupBy('line_id')->sortByDesc(fn ($g) => $g->count())->keys()->first();

        $merged = GangsheetLabel::create([
            'gangsheet_id' => null,                 // not tied to a single source gangsheet
            'created_by_user_id' => $request->user()?->id,
            'name' => 'merged_' . now()->format('Ymd_His'),
            'line_id' => $dominantLineId ?: null,
            'order_ids' => $orderIds,
            'merged_from_label_ids' => $labels->pluck('id')->all(),
            'orders_count' => count($orderIds),
            'total_items_qty' => $totalItemsQty,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => "Merged {$labels->count()} label(s) → " . count($orderIds) . " order(s)",
            'merged_label' => $merged->fresh(),
            'scan_code' => $merged->scan_code, // GSL{id}
            'order_ids' => $orderIds,
        ], 201);
    }

    /**
     * PUT /api/gangsheet-labels/{gangsheetLabel}/file-url — store the B2
     * public URL of the merged PDF after the desktop app uploads it.
     */
    public function updateFileUrl(Request $request, GangsheetLabel $gangsheetLabel)
    {
        $request->validate(['file_url' => 'required|url']);
        $gangsheetLabel->update(['file_url' => $request->file_url]);
        return response()->json(['message' => 'File URL saved', 'gangsheet_label' => $gangsheetLabel->fresh()]);
    }
}
