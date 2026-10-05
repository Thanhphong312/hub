<?php

namespace App\Http\Controllers;

use App\Models\MergedLabel;
use App\Models\Order;
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
     * GET /api/push-scan/stale — orders created more than 1.5 days ago whose
     * delivery tracking is still stuck at pre_shipment / accepted. The label
     * exists but USPS hasn't actually moved the package, so these likely need a
     * (re)push scan. Oldest first. Each row carries `already_pushed` so the UI
     * can flag orders already queued in push_trackings.
     */
    public function staleTracking(Request $request)
    {
        $cutoff   = now()->subHours(36); // 1.5 days
        $statuses = ['pre_shipment', 'accepted'];

        $query = Order::query()
            ->select('id', 'user_id', 'system_id', 'ref_id', 'status', 'ship_type', 'shipping_label', 'tracking_id', 'paid_cost', 'total_cost', 'push_scan_fee', 'created_at')
            ->with([
                'user:id,name,email',
                'tracking:id,order_id,status,status_description,last_event_at,delivered_at,checked_at',
            ])
            ->whereNotNull('tracking_id')
            ->where('tracking_id', '!=', '')
            ->where('created_at', '<', $cutoff)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->whereHas('tracking', fn ($q) => $q->whereIn('status', $statuses))
            ->orderBy('created_at'); // most overdue first

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(fn ($q) => $q->where('system_id', 'like', "%{$s}%")
                ->orWhere('ref_id', 'like', "%{$s}%")
                ->orWhere('tracking_id', 'like', "%{$s}%"));
        }

        $result = $query->paginate(min(500, (int) $request->input('per_page', 200)));

        // Flag orders already queued in push_trackings so staff don't double-push.
        $pushed = PushTracking::whereIn('order_id', collect($result->items())->pluck('id'))
            ->pluck('order_id')->flip();
        $result->getCollection()->transform(function ($o) use ($pushed) {
            $o->already_pushed = isset($pushed[$o->id]);
            return $o;
        });

        return response()->json($result);
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
     * DELETE /api/push-scan/merged-labels/{mergedLabel}?refund=0|1 — delete the
     * merged label AND drop its orders from push scan entirely (their
     * push_trackings rows go, whatever the status), as if never pushed.
     *
     * refund=1 also takes each row's push-scan fee back off the order:
     * push_scan_fee and total_cost shrink, and what the seller had already
     * paid beyond the new total is credited to their wallet (refund tx).
     * refund=0 keeps the fee on the orders.
     */
    public function destroyMergedLabel(Request $request, MergedLabel $mergedLabel)
    {
        $refundFee = $request->boolean('refund');

        return DB::transaction(function () use ($mergedLabel, $refundFee) {
            $rows = PushTracking::with('order.user')->where('merged_id', $mergedLabel->id)->lockForUpdate()->get();
            $refunded = 0.0;
            $feeRemoved = 0.0;

            foreach ($rows as $row) {
                $order = $row->order;
                $fee = round((float) $row->fee, 2);
                if ($refundFee && $order && $fee > 0) {
                    $newFee = max(0, round((float) $order->push_scan_fee - $fee, 2));
                    $newTotal = max(0, round((float) $order->total_cost - $fee, 2));
                    $paid = round((float) $order->paid_cost, 2);
                    $back = min($fee, max(0, round($paid - $newTotal, 2)));
                    $updates = ['push_scan_fee' => $newFee, 'total_cost' => $newTotal];

                    if ($back > 0 && $order->user) {
                        $user = \App\Models\User::lockForUpdate()->find($order->user_id);
                        $before = (float) $user->wallet;
                        $user->increment('wallet', $back);
                        $updates['paid_cost'] = round($paid - $back, 2);
                        \App\Models\WalletTransaction::create([
                            'user_id' => $user->id,
                            'order_id' => $order->id,
                            'order_system_id' => $order->system_id,
                            'type' => 'refund',
                            'status' => \App\Models\WalletTransaction::STATUS_APPROVED,
                            'amount' => $back,
                            'balance_before' => $before,
                            'balance_after' => (float) $user->fresh()->wallet,
                            'refunded_at' => now(),
                            'note' => "Refund push scan #{$order->system_id} (xoá merged label #{$mergedLabel->id})",
                        ]);
                        $refunded += $back;
                    }
                    $order->update($updates);
                    $feeRemoved += $fee;
                }
                $row->delete();
            }
            $mergedLabel->delete();

            return response()->json([
                'message' => "Đã xoá merged label #{$mergedLabel->id} · bỏ push scan {$rows->count()} đơn"
                    .($refundFee
                        ? ' · hoàn phí $'.number_format($feeRemoved, 2).($refunded > 0 ? ' (trả về ví $'.number_format($refunded, 2).')' : '')
                        : ' · giữ phí'),
                'removed' => $rows->count(),
                'fee_removed' => round($feeRemoved, 2),
                'wallet_refunded' => round($refunded, 2),
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
