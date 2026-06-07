<?php

namespace App\Http\Controllers;

use App\Models\Gangsheet;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Endpoints for the partner-bullstart app. A partner only ever sees the
 * gangsheets an admin assigned to them (gangsheet_partner pivot). See
 * doc/partner.md.
 */
class PartnerController extends Controller
{
    /** Dashboard totals: assigned gangsheets + distinct orders across them. */
    public function dashboard(Request $request)
    {
        $user = $request->user();

        $gangs = $user->assignedGangsheets()->get(['gangsheets.id', 'order_ids', 'metas_count']);
        $orderIds = [];
        foreach ($gangs as $g) {
            foreach (($g->order_ids ?? []) as $oid) $orderIds[$oid] = true;
        }

        return response()->json([
            'total_gangsheets' => $gangs->count(),
            'total_orders'     => count($orderIds),
            'total_metas'      => (int) $gangs->sum('metas_count'),
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
                ->select('id', 'system_id', 'ref_id', 'status', 'total_cost')
                ->orderBy('id', 'asc')
                ->get();

        return response()->json(['gangsheet' => $gangsheet, 'orders' => $orders]);
    }
}
