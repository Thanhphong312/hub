<?php

namespace App\Http\Controllers;

use App\Models\AccessoryPrice;
use App\Models\Order;
use App\Models\OrderItemMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Bulk-swap the `front` design of orders in one status, picking the new URL by
 * the item's chip add-on (small chip vs big chip get different artwork).
 *
 * Built for the recurring "on-hold orders need the new design" job, which was
 * previously done by hand order by order. Always preview first (dry_run) — the
 * response is the exact list that would be written.
 */
class BulkFrontUpdateController extends Controller
{
    /** Meta key holding the source design this replaces. */
    private const SOURCE_KEY = 'front';

    /** Matches every produced _qr meta key: front_qr, front_qr_1, back_qr_3… */
    private const QR_KEY_REGEX = '_qr(_[0-9]+)?$';

    public function run(Request $request)
    {
        $data = $request->validate([
            'status'        => 'nullable|integer|min:0|max:7',
            'mapping'       => 'required|array|min:1',
            'mapping.*'     => 'nullable|string|max:2000',
            'user_id'       => 'nullable|integer|exists:users,id',
            'order_ids'     => 'nullable|array',
            'order_ids.*'   => 'integer',
            'dry_run'       => 'nullable|boolean',
            'reconvert'     => 'nullable|boolean',
        ]);

        $status    = array_key_exists('status', $data) && $data['status'] !== null
            ? (int) $data['status']
            : Order::STATUS_ONHOLD;
        $dryRun    = $request->boolean('dry_run', true);
        $reconvert = $request->boolean('reconvert', true);

        // Only chips we were actually given a URL for take part.
        $mapping = [];
        foreach ($data['mapping'] as $chip => $url) {
            $url = trim((string) $url);
            if ($url !== '') $mapping[strtolower($chip)] = $url;
        }
        if (empty($mapping)) {
            return response()->json(['message' => 'No URL given for any chip'], 422);
        }

        // Reachability check, once per distinct URL. A Drive file that is not
        // shared publicly answers with the HTML sign-in page, which would be
        // written into the meta and then silently break every conversion — so
        // an HTML answer is a hard stop, not a warning.
        $urlCheck = [];
        foreach (array_unique($mapping) as $url) {
            $urlCheck[$url] = $this->checkUrl($url);
        }
        $badUrls = array_filter($urlCheck, fn ($c) => !$c['ok']);
        if (!empty($badUrls) && !$dryRun) {
            return response()->json([
                'message'   => 'Some design URLs are not reachable as an image — fix sharing/permissions first',
                'url_check' => $urlCheck,
            ], 422);
        }

        $query = Order::with([
            'user:id,name,email',
            'items.metas',
            'items.accessoryPrice',
            'items.accessoryPrices',
        ])->where('status', $status);

        if (!empty($data['user_id'])) {
            $query->where('user_id', (int) $data['user_id']);
        }
        if (!empty($data['order_ids'])) {
            $query->whereIn('id', $data['order_ids']);
        }

        $rows = [];
        $skipped = [];
        $byChip = [];
        $byUser = [];

        foreach ($query->orderBy('id')->get() as $order) {
            foreach ($order->items as $item) {
                $chip = $this->itemChipKind($item);
                if ($chip === '') {
                    $skipped[] = ['order_id' => $order->id, 'system_id' => $order->system_id,
                                  'item_id' => $item->id, 'reason' => 'no chip add-on'];
                    continue;
                }
                if (!isset($mapping[$chip])) {
                    $skipped[] = ['order_id' => $order->id, 'system_id' => $order->system_id,
                                  'item_id' => $item->id, 'reason' => "no URL given for {$chip}"];
                    continue;
                }

                $front = $item->metas->firstWhere('key', self::SOURCE_KEY);
                $qr = $item->metas->filter(fn ($m) => preg_match('/' . self::QR_KEY_REGEX . '/', $m->key));

                $rows[] = [
                    'order_id'   => $order->id,
                    'system_id'  => $order->system_id,
                    'user_id'    => $order->user_id,
                    'user_name'  => $order->user->name ?? null,
                    'item_id'    => $item->id,
                    'chip'       => $chip,
                    'front_old'  => $front->value ?? null,
                    'front_new'  => $mapping[$chip],
                    'qr_count'   => $qr->count(),
                    'qr_in_gang' => $qr->where('production', true)->count(),
                    'changed'    => ($front->value ?? null) !== $mapping[$chip],
                ];
                $byChip[$chip] = ($byChip[$chip] ?? 0) + 1;
                $uname = $order->user->name ?? ('user#' . $order->user_id);
                $byUser[$uname] = ($byUser[$uname] ?? 0) + 1;
            }
        }

        $summary = [
            'status'        => $status,
            'matched_items' => count($rows),
            'will_change'   => count(array_filter($rows, fn ($r) => $r['changed'])),
            'by_chip'       => $byChip,
            'by_user'       => $byUser,
            'skipped'       => count($skipped),
            'qr_to_delete'  => array_sum(array_column($rows, 'qr_count')),
        ];

        if ($dryRun) {
            return response()->json([
                'dry_run'   => true,
                'summary'   => $summary,
                'url_check' => $urlCheck,
                'rows'      => $rows,
                'skipped'   => $skipped,
            ]);
        }

        $applied = DB::transaction(function () use ($rows, $reconvert) {
            $updated = 0;
            foreach ($rows as $r) {
                if (!$r['changed']) continue;
                OrderItemMeta::updateOrCreate(
                    ['order_item_id' => $r['item_id'], 'key' => self::SOURCE_KEY],
                    ['value' => $r['front_new']],
                );
                $updated++;
            }

            $deleted = 0;
            $requeued = 0;
            if ($reconvert) {
                // Same shape as OrderController::bulkReconvert — drop the _qr
                // metas and clear production so the converter rebuilds them from
                // the new design and the order returns to the compose queue.
                $itemIds  = array_unique(array_column($rows, 'item_id'));
                $orderIds = array_unique(array_column($rows, 'order_id'));
                $deleted = OrderItemMeta::whereIn('order_item_id', $itemIds)
                    ->where('key', 'regexp', self::QR_KEY_REGEX)
                    ->delete();
                $requeued = Order::whereIn('id', $orderIds)->update(['production' => false]);
            }

            return ['updated' => $updated, 'qr_deleted' => $deleted, 'orders_requeued' => $requeued];
        });

        return response()->json([
            'dry_run'   => false,
            'message'   => "Updated {$applied['updated']} front design(s); removed {$applied['qr_deleted']} _qr meta(s).",
            'summary'   => $summary,
            'applied'   => $applied,
            'url_check' => $urlCheck,
        ]);
    }

    /** Chip of an item, from the multi pivot first, then the legacy single. */
    private function itemChipKind($item): string
    {
        foreach ($item->accessoryPrices as $ap) {
            $kind = $ap->chipKindOfSelf();
            if ($kind !== '') return $kind;
        }

        return $item->accessoryPrice?->chipKindOfSelf() ?? '';
    }

    /**
     * Is this URL really an image? Drive share links point at the viewer page,
     * so normalise to the direct-download form first — the same rewrite the
     * desktop composer does before fetching.
     */
    private function checkUrl(string $url): array
    {
        $target = $url;
        if (preg_match('#(?:/file/d/|[?&]id=)([a-zA-Z0-9_-]+)#', $url, $m)) {
            $target = 'https://drive.google.com/uc?export=download&id=' . $m[1];
        }

        try {
            $res = Http::timeout(25)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($target);
            $type = strtolower((string) $res->header('Content-Type'));
            $ok = $res->successful() && !str_contains($type, 'text/html');

            return [
                'ok'           => $ok,
                'checked_url'  => $target,
                'http_status'  => $res->status(),
                'content_type' => $type,
                'bytes'        => strlen($res->body()),
                'reason'       => $ok ? null : (str_contains($type, 'text/html')
                    ? 'Drive returned an HTML page — the file is probably not shared publicly'
                    : 'HTTP ' . $res->status()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'checked_url' => $target, 'reason' => $e->getMessage()];
        }
    }
}
