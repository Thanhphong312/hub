<?php

namespace App\Http\Controllers;

use App\Models\MergedLabel;
use App\Models\PushTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/**
 * Push scan page (desktop, staff only). Sellers push orders from the web app
 * (OrderController::pushScan); here staff bundle the shipping labels of
 * pending pushes into one merged label PDF and track whether it was sent.
 *
 * push_trackings.status: 1 pending → 2 merged (bundled here) → 3 run (the
 * cron-tracking app flips it once the order is in transit).
 */
class PushTrackingController extends Controller
{
    /** GET /api/push-scan?status=1&search=&per_page= */
    public function index(Request $request)
    {
        $query = PushTracking::with([
            'order:id,user_id,system_id,ref_id,status,ship_type,shipping_label,tracking_id,paid_cost,total_cost,push_scan_fee',
            'order.user:id,name,email',
            'mergedLabel:id,label_url,status',
        ])->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', (int) $request->status);
        }
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->whereHas('order', fn ($q) => $q->where('system_id', 'like', "%{$s}%")
                ->orWhere('ref_id', 'like', "%{$s}%")
                ->orWhere('tracking_id', 'like', "%{$s}%"));
        }

        return response()->json($query->paginate(min(500, (int) $request->input('per_page', 100))));
    }

    /**
     * POST /api/push-scan/merge — { push_tracking_ids: int[], label_url: string }.
     * The desktop app builds + uploads the merged PDF first, then calls this,
     * so a failed build/upload never leaves rows stuck in "merged".
     */
    public function merge(Request $request)
    {
        $data = $request->validate([
            'push_tracking_ids' => 'required|array|min:1',
            'push_tracking_ids.*' => 'integer|exists:push_trackings,id',
            'label_url' => 'required|url',
        ]);

        return DB::transaction(function () use ($data) {
            $rows = PushTracking::whereIn('id', $data['push_tracking_ids'])
                ->where('status', PushTracking::STATUS_PENDING)
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                return response()->json(['message' => 'Không có đơn pending nào trong danh sách đã chọn'], 422);
            }

            $merged = MergedLabel::create([
                'label_url' => $data['label_url'],
                'status' => MergedLabel::STATUS_NOT_SENT,
            ]);
            PushTracking::whereIn('id', $rows->pluck('id'))->update([
                'merged_id' => $merged->id,
                'status' => PushTracking::STATUS_MERGED,
            ]);

            return response()->json([
                'message' => "Đã gộp {$rows->count()} đơn vào merged label #{$merged->id}",
                'merged_label' => $merged->loadCount('pushTrackings'),
            ], 201);
        });
    }

    /** GET /api/push-scan/merged-labels?status= */
    public function mergedLabels(Request $request)
    {
        $query = MergedLabel::withCount('pushTrackings')
            ->with(['pushTrackings:id,order_id,merged_id,status', 'pushTrackings.order:id,system_id'])
            ->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', (int) $request->status);
        }

        return response()->json($query->paginate(min(200, (int) $request->input('per_page', 50))));
    }

    /** PUT /api/push-scan/merged-labels/{mergedLabel}/status — { status: 1|2 } */
    public function updateMergedLabelStatus(Request $request, MergedLabel $mergedLabel)
    {
        $data = $request->validate([
            'status' => ['required', 'integer', Rule::in([MergedLabel::STATUS_NOT_SENT, MergedLabel::STATUS_SENT])],
        ]);
        $mergedLabel->update($data);

        return response()->json(['message' => 'Status updated', 'merged_label' => $mergedLabel->loadCount('pushTrackings')]);
    }

    /**
     * GET /api/push-scan/{pushTracking}/shipping-label-blob — proxy the order's
     * shipping_label bytes (PDF or image) so the desktop renderer can merge
     * them without B2/Drive CORS issues.
     */
    public function shippingLabelBlob(PushTracking $pushTracking)
    {
        $url = $pushTracking->order?->shipping_label;
        if (empty($url)) {
            return response()->json(['message' => 'Order has no shipping_label'], 404);
        }

        try {
            $res = Http::timeout(20)->get($url);
            if (! $res->ok()) {
                return response()->json(['message' => "Upstream returned HTTP {$res->status()}"], 502);
            }

            return response($res->body(), 200, [
                'Content-Type' => $res->header('Content-Type') ?: 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
