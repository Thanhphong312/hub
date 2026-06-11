<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Reprint;
use Illuminate\Http\Request;

class ReprintController extends Controller
{
    /**
     * Reprints are user-scoped: a user sees only the ones they created; admins
     * see everyone's. Mirrors the seller order-scoping convention.
     */
    private function scopedQuery(Request $request)
    {
        $q = Reprint::with([
            'reason:id,name,code',
            'user:id,name',
            'order:id,system_id,status',
        ]);
        if (!$request->user()->hasRole('admin')) {
            $q->where('user_id', $request->user()->id);
        }
        return $q;
    }

    public function index(Request $request)
    {
        $q = $this->scopedQuery($request);

        if ($request->filled('reason_id')) {
            $q->where('reason_id', $request->reason_id);
        }
        // no_reason=1 → reprints still missing a reason (the scan-to-fill target).
        if ($request->boolean('no_reason')) {
            $q->whereNull('reason_id');
        }
        // done=0/1 → filter by whether a gangsheet has been made.
        if ($request->filled('done')) {
            $q->where('gangsheet_done', $request->boolean('done'));
        }
        if ($request->filled('system_id')) {
            $q->where('system_id', 'like', '%' . trim($request->system_id) . '%');
        }

        return response()->json($q->orderByDesc('id')->limit(500)->get());
    }

    /**
     * Create a reprint from a scanned/typed system_id. reason_id is optional
     * (the operator may scan the reason QR afterwards).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'system_id' => 'required|string|max:255',
            'reason_id' => 'nullable|exists:reasons,id',
            'note'      => 'nullable|string',
        ]);

        $systemId = trim($data['system_id']);
        $order = Order::where('system_id', $systemId)->first();

        $reprint = Reprint::create([
            'system_id' => $systemId,
            'order_id'  => $order?->id,
            'user_id'   => $request->user()->id,
            'reason_id' => $data['reason_id'] ?? null,
            'note'      => $data['note'] ?? null,
        ]);

        return response()->json(
            $reprint->load(['reason:id,name,code', 'order:id,system_id,status']),
            201
        );
    }

    /** Update a reprint — used to fill the reason (scan QR) or edit the note. */
    public function update(Request $request, Reprint $reprint)
    {
        $this->authorizeOwner($request, $reprint);

        $data = $request->validate([
            'reason_id' => 'nullable|exists:reasons,id',
            'note'      => 'nullable|string',
        ]);
        $reprint->update($data);

        return response()->json($reprint->fresh()->load(['reason:id,name,code', 'order:id,system_id,status']));
    }

    public function destroy(Request $request, Reprint $reprint)
    {
        $this->authorizeOwner($request, $reprint);
        $reprint->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Mark a set of reprints as having been put on a gangsheet. Called after the
     * client builds the gang from the selected reprints. Owner/admin scoped.
     */
    public function markGangsheet(Request $request)
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
            'done'  => 'nullable|boolean',
        ]);
        $done = $request->boolean('done', true);

        $q = Reprint::whereIn('id', $data['ids']);
        if (!$request->user()->hasRole('admin')) {
            $q->where('user_id', $request->user()->id);
        }
        $affected = $q->update([
            'gangsheet_done'    => $done,
            'gangsheet_done_at' => $done ? now() : null,
        ]);

        return response()->json(['ok' => true, 'updated' => $affected]);
    }

    private function authorizeOwner(Request $request, Reprint $reprint): void
    {
        if (!$request->user()->hasRole('admin') && $reprint->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
