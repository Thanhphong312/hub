<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\GangsheetLabel;
use App\Models\Order;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GangsheetLabelWebController extends Controller
{
    /**
     * Show the gangsheet-label scan page. Accepts either the numeric id or
     * the "GSL123" scan code (the scanner on /qr trims the prefix and
     * redirects here when it sees one).
     */
    public function show(Request $request, string $code)
    {
        $label = $this->resolve($code);

        $orderIds = $label->order_ids ?? [];
        $orders = empty($orderIds)
            ? collect()
            : Order::whereIn('id', $orderIds)
                ->with(['items.productVariant.product', 'items.accessoryPrice.accessory', 'user'])
                ->orderBy('id', 'asc')
                ->get()
                ->each(fn ($o) => $o->makeVisible('convert_label'));

        $totalItemsQty = $orders->sum(fn ($o) => $o->items->sum('quantity'));
        $shippedCount = $orders->where('status', Order::STATUS_SHIPPED)->count();

        return view('gs.show', [
            'label' => $label,
            'orders' => $orders,
            'totalItemsQty' => $totalItemsQty,
            'shippedCount' => $shippedCount,
            'scanCode' => $label->scan_code,
            'user' => $request->user(),
        ]);
    }

    /**
     * Bulk-complete every order in the label group. Same logic as the
     * authenticated API endpoint but exposed to the portal session.
     */
    public function completeAll(Request $request, string $code)
    {
        $label = $this->resolve($code);
        $orderIds = $label->order_ids ?? [];

        $affected = 0;
        if (!empty($orderIds)) {
            $toShip = Order::with('items')->whereIn('id', $orderIds)
                ->where('status', '!=', Order::STATUS_SHIPPED)
                ->get();
            foreach ($toShip as $o) {
                $o->update(['status' => Order::STATUS_SHIPPED, 'completed_time' => now()]);
                $o->decrementStockForShipment();
                $affected++;
            }
            $label->update(['status' => 'completed', 'completed_at' => now()]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'count' => $affected]);
        }
        return redirect()->route('gs.show', ['code' => $label->scan_code])
            ->with('status_message', "Marked {$affected} order(s) as shipped.");
    }

    private function resolve(string $code): GangsheetLabel
    {
        $needle = trim($code);
        // Accept either "GSL123" (scanned) or "123" (typed).
        if (preg_match('/^GSL(\d+)$/i', $needle, $m)) {
            $id = (int) $m[1];
        } elseif (ctype_digit($needle)) {
            $id = (int) $needle;
        } else {
            throw new NotFoundHttpException("Bad gangsheet label code: {$needle}");
        }
        $label = GangsheetLabel::find($id);
        if (!$label) throw new NotFoundHttpException("Gangsheet label {$needle} not found");
        return $label;
    }
}
