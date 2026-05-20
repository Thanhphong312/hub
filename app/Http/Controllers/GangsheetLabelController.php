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
}
