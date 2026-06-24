<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderImageValidation;
use App\Services\ImageValidationService;
use Illuminate\Http\Request;

/**
 * Image-URL validation status for orders. Persistence lives in the DB
 * (order_image_validations + order_image_validation_items); the actual image
 * checking is done by the desktop client (Electron) and POSTed here via store().
 * See App\Services\ImageValidationService.
 */
class OrderImageValidationController extends Controller
{
    public function __construct(private ImageValidationService $service) {}

    /**
     * GET /api/orders/image-validation?order_ids[]=1&order_ids[]=2
     *
     * Read-only: returns the stored validation status for each requested order.
     * Orders never validated come back with status 'pending'; the client then
     * checks them itself and saves via store(). Shipped orders report 'shipped'.
     *
     * Response: { data: { "<orderId>": { ...status... }, ... } }
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'order_ids'   => 'required|array|min:1|max:200',
            'order_ids.*' => 'integer',
        ]);

        $user = $request->user();

        $query = Order::query()
            ->whereIn('id', $validated['order_ids'])
            ->with(['imageValidation', 'imageValidationFailures']);

        // Sellers only see their own orders (same scoping as OrderController).
        if ($user?->hasRole('seller')) {
            $query->where('user_id', $user->id);
        }

        $out = [];
        foreach ($query->get() as $order) {
            $shipped = $order->status === Order::STATUS_SHIPPED;
            $out[(string) $order->id] = $this->present($order, $order->imageValidation, $shipped);
        }

        return response()->json(['data' => $out]);
    }

    /**
     * GET /api/orders/image-validation/manage
     *
     * Management list: NON-shipped orders only, joined with their stored
     * validation status. Powers the dedicated "Image URL Check" page.
     *
     * Query: page, per_page (max 200), filter = all|issues|unchecked|ok,
     *        user_id (admin only). Sellers are scoped to their own orders.
     *
     * Response: { data: [ { id, system_id, ref_id, user, status, validation } ],
     *             meta: { total, page, per_page, last_page },
     *             counts: { total, checked, unchecked, issues } }
     */
    public function manage(Request $request)
    {
        $request->validate([
            'page'     => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:200',
            'filter'   => 'sometimes|in:all,issues,unchecked,ok',
            'user_id'  => 'sometimes|integer',
        ]);

        $user   = $request->user();
        $filter = $request->get('filter', 'all');

        $base = Order::query()
            ->where('status', '!=', Order::STATUS_SHIPPED)
            ->with(['user:id,name', 'imageValidation']);

        if ($user?->hasRole('seller')) {
            $base->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $base->where('user_id', $request->user_id);
        }

        // Validation-state filters (left join via the imageValidation relation).
        $applyFilter = function ($q) use ($filter) {
            if ($filter === 'unchecked') {
                $q->where(fn ($w) => $w->whereDoesntHave('imageValidation')
                    ->orWhereHas('imageValidation', fn ($v) => $v->where('checked', false)));
            } elseif ($filter === 'issues') {
                $q->whereHas('imageValidation', fn ($v) => $v->where('checked', true)->where('failed_count', '>', 0));
            } elseif ($filter === 'ok') {
                $q->whereHas('imageValidation', fn ($v) => $v->where('checked', true)->where('failed_count', 0));
            }
        };

        // Counts across the whole non-shipped set (independent of the page filter).
        $countsBase = (clone $base);
        $counts = [
            'total'     => (clone $countsBase)->count(),
            'checked'   => (clone $countsBase)->whereHas('imageValidation', fn ($v) => $v->where('checked', true))->count(),
            'issues'    => (clone $countsBase)->whereHas('imageValidation', fn ($v) => $v->where('checked', true)->where('failed_count', '>', 0))->count(),
        ];
        $counts['unchecked'] = $counts['total'] - $counts['checked'];

        $q = (clone $base);
        $applyFilter($q);

        $perPage = (int) $request->get('per_page', 50);
        $page    = $q->orderByDesc('id')->paginate($perPage);

        $data = collect($page->items())->map(function ($order) {
            $v = $order->imageValidation;
            return [
                'id'        => $order->id,
                'system_id' => $order->system_id,
                'ref_id'    => $order->ref_id,
                'status'    => Order::STATUS_MAP[$order->status] ?? 'unknown',
                'user'      => $order->user?->name,
                'validation' => [
                    'checked'      => (bool) ($v->checked ?? false),
                    'status'       => $v->status ?? OrderImageValidation::STATUS_PENDING,
                    'image_count'  => (int) ($v->image_count ?? 0),
                    'failed_count' => (int) ($v->failed_count ?? 0),
                    'checked_at'   => optional($v?->checked_at)->toIso8601String(),
                ],
            ];
        });

        return response()->json([
            'data'   => $data,
            'meta'   => [
                'total'     => $page->total(),
                'page'      => $page->currentPage(),
                'per_page'  => $page->perPage(),
                'last_page' => $page->lastPage(),
            ],
            'counts' => $counts,
        ]);
    }

    /**
     * POST /api/orders/{order}/image-validation
     *
     * Save a validation result the client computed for one order. Body:
     *   { image_count: int, failures: [
     *       { order_item_id?: int, field: string, url?: string, reason: string,
     *         http_status?: int, content_type?: string }, ... ] }
     * An empty `failures` list records a pass. Used both for the initial
     * lazy check on the Orders page and the "Re-check images" button.
     */
    public function store(Request $request, Order $order)
    {
        $user = $request->user();
        if ($user?->hasRole('seller') && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($order->status === Order::STATUS_SHIPPED) {
            return response()->json(['message' => 'Shipped orders are not validated'], 422);
        }

        $data = $request->validate([
            'image_count'              => 'required|integer|min:0',
            'failures'                 => 'present|array',
            'failures.*.order_item_id' => 'nullable|integer',
            'failures.*.field'         => 'required|string|max:191',
            'failures.*.url'           => 'nullable|string',
            'failures.*.reason'        => 'required|string|max:191',
            'failures.*.http_status'   => 'nullable|integer',
            'failures.*.content_type'  => 'nullable|string|max:191',
        ]);

        $summary = $this->service->saveResult($order, (int) $data['image_count'], $data['failures']);
        $order->load('imageValidationFailures');

        return response()->json(['data' => $this->present($order, $summary, false)]);
    }

    /**
     * Shape one order's validation status for the API response.
     */
    private function present(Order $order, ?OrderImageValidation $summary, bool $shipped): array
    {
        $failures = $order->imageValidationFailures
            ->map(fn ($f) => [
                'order_item_id' => $f->order_item_id,
                'field'         => $f->field,
                'url'           => $f->url,
                'reason'        => $f->reason,
            ])->values();

        return [
            'order_id'     => $order->id,
            'checked'      => (bool) ($summary->checked ?? false),
            'status'       => $shipped ? 'shipped' : ($summary->status ?? OrderImageValidation::STATUS_PENDING),
            'image_count'  => (int) ($summary->image_count ?? 0),
            'failed_count' => (int) ($summary->failed_count ?? 0),
            'checked_at'   => optional($summary?->checked_at)->toIso8601String(),
            'failures'     => $failures,
        ];
    }
}
