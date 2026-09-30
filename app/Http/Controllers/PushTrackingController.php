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
            // Delivery tracking state, shown next to the tracking number.
            'order.tracking:id,order_id,status,status_description,last_event_at,delivered_at,checked_at',
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
     * POST /api/push-scan/merged-labels/{mergedLabel}/cancel — undo a merge:
     * its merged rows go back to pending (merged_id cleared) so they can be
     * merged again. Rows the tracking cron already marked run stay put; the
     * merged label is deleted once nothing points at it any more.
     */
    public function cancelMergedLabel(MergedLabel $mergedLabel)
    {
        return DB::transaction(function () use ($mergedLabel) {
            $reverted = PushTracking::where('merged_id', $mergedLabel->id)
                ->where('status', PushTracking::STATUS_MERGED)
                ->lockForUpdate()
                ->update(['status' => PushTracking::STATUS_PENDING, 'merged_id' => null]);

            $remaining = PushTracking::where('merged_id', $mergedLabel->id)->count();
            if ($remaining === 0) {
                $mergedLabel->delete();
            }

            return response()->json([
                'message' => "Đã huỷ scan: {$reverted} đơn về pending"
                    .($remaining > 0 ? " · {$remaining} đơn đã run nên giữ lại trong merged label #{$mergedLabel->id}" : " · đã xoá merged label #{$mergedLabel->id}"),
                'reverted' => $reverted,
                'remaining' => $remaining,
                'deleted' => $remaining === 0,
            ]);
        });
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

        // Drive share links (/file/d/<id>/view) serve the HTML viewer, not the
        // file — fetch the direct-download endpoint instead.
        $driveId = app(\App\Services\ImageValidationService::class)->driveId($url);
        $fetchUrl = $driveId ? "https://drive.google.com/uc?export=download&id={$driveId}" : $url;

        try {
            $res = Http::timeout(20)->get($fetchUrl);
            if (! $res->ok()) {
                return response()->json(['message' => "Upstream returned HTTP {$res->status()}"], 502);
            }
            $body = $res->body();
            $type = $res->header('Content-Type') ?: '';

            // Drive answers big files with a "can't scan for viruses" page —
            // follow its confirm token once.
            if ($driveId && $this->isHtml($body, $type) && preg_match('/confirm=([0-9A-Za-z_-]+)/', $body, $m)) {
                $res = Http::timeout(20)->get("https://drive.google.com/uc?export=download&confirm={$m[1]}&id={$driveId}");
                if ($res->ok()) {
                    $body = $res->body();
                    $type = $res->header('Content-Type') ?: '';
                }
            }

            // Still a web page (private file, deleted, login wall…) — say so
            // instead of handing HTML to the PDF builder.
            if ($this->isHtml($body, $type)) {
                return response()->json(['message' => 'shipping_label is not a file (link returns a web page — check sharing permission)'], 502);
            }

            return response($body, 200, [
                'Content-Type' => $type ?: 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    private function isHtml(string $body, string $contentType): bool
    {
        return str_contains($contentType, 'text/html') || str_starts_with(ltrim(substr($body, 0, 64)), '<');
    }
}
