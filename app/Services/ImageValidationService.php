<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderImageValidation;
use App\Models\OrderImageValidationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Image-URL validation persistence + the synchronous check used by order
 * creation.
 *
 * The bulk of validation now runs in the desktop client (it fetches each image
 * via the Electron main process, which bypasses CORS) and POSTs the result here
 * via saveResult() — so no queue worker is needed. The server still performs the
 * check itself in ONE place: the blocking create flow (validatePayload()),
 * which must reject bad URLs before an order is created.
 *
 * A URL is valid when it is reachable (HTTP 2xx) AND the response is actually an
 * image (Content-Type: image/*). Google Drive `/view` pages are normalized to
 * the thumbnail endpoint, which returns real image bytes for publicly-shared
 * files and 403/HTML otherwise — so the check also reflects sharing status.
 */
class ImageValidationService
{
    /**
     * Bump this when the validation logic changes in a way that should
     * invalidate previously-stored results. Stored on each summary row.
     */
    const VERSION = '1';

    private const DRIVE_ID_RE = '/(?:\/file\/d\/|[?&]id=)([a-zA-Z0-9_-]+)/';

    // ---- Drive helpers (port of drive.jsx) -------------------------------

    public function driveId(?string $url): ?string
    {
        if (!$url) return null;
        return preg_match(self::DRIVE_ID_RE, $url, $m) ? $m[1] : null;
    }

    public function driveThumb(string $url, string $size = 'w400'): string
    {
        $id = $this->driveId($url);
        return $id ? "https://drive.google.com/thumbnail?id={$id}&sz={$size}" : $url;
    }

    public function isPreviewable(?string $url): bool
    {
        if (!$url) return false;
        return (bool) preg_match('/^https?:\/\//i', $url) || $this->driveId($url) !== null;
    }

    public function normalizeForCheck(?string $url): ?string
    {
        if (!$url) return $url;
        return $this->driveId($url) ? $this->driveThumb($url, 'w400') : $url;
    }

    // ---- Single-URL validation -------------------------------------------

    /**
     * Validate one URL. Returns:
     *   ['ok' => true]
     *   ['ok' => false, 'reason' => string, 'http_status' => ?int, 'content_type' => ?string]
     */
    public function validateUrl(?string $url): array
    {
        if (!$url || !$this->isPreviewable($url)) {
            return ['ok' => false, 'reason' => 'not a valid URL', 'http_status' => null, 'content_type' => null];
        }

        $target = $this->normalizeForCheck($url);

        try {
            $res = Http::timeout(15)->withHeaders(['User-Agent' => 'BullstartImageValidator/1.0'])->get($target);

            if (!$res->successful()) {
                return [
                    'ok' => false,
                    'reason' => $this->reasonFromStatus($res->status()),
                    'http_status' => $res->status(),
                    'content_type' => $res->header('Content-Type') ?: null,
                ];
            }

            $ct = strtolower((string) $res->header('Content-Type'));
            if (!str_starts_with($ct, 'image/')) {
                return [
                    'ok' => false,
                    'reason' => 'URL is not an image',
                    'http_status' => $res->status(),
                    'content_type' => $res->header('Content-Type') ?: null,
                ];
            }

            return ['ok' => true];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'reason' => 'could not load (network/blocked)',
                'http_status' => null,
                'content_type' => null,
            ];
        }
    }

    private function reasonFromStatus(int $status): string
    {
        if ($status === 401 || $status === 403) return 'no permission / not shared publicly';
        if ($status === 404) return 'not found';
        return "unreachable (HTTP {$status})";
    }

    // ---- URL collection --------------------------------------------------

    /**
     * Collect the validatable image URLs from a raw items payload (store() —
     * no models yet). Returns rows tagged with a 1-based item number for messaging:
     *   ['itemNo' => int, 'field' => string, 'label' => string, 'url' => string]
     */
    public function collectPayloadUrls(array $items): array
    {
        $out = [];
        foreach (array_values($items) as $idx => $it) {
            $no = $idx + 1;
            if ($this->isPreviewable($it['mockup_front'] ?? null)) {
                $out[] = ['itemNo' => $no, 'field' => 'mockup_front', 'label' => 'Mockup front', 'url' => $it['mockup_front']];
            }
            if ($this->isPreviewable($it['mockup_back'] ?? null)) {
                $out[] = ['itemNo' => $no, 'field' => 'mockup_back', 'label' => 'Mockup back', 'url' => $it['mockup_back']];
            }
            foreach ($it['metas'] ?? [] as $meta) {
                $key = $meta['key'] ?? '';
                if ($key === '' || preg_match('/_qr(_\d+)?$/', $key)) continue;
                if ($this->isPreviewable($meta['value'] ?? null)) {
                    $out[] = ['itemNo' => $no, 'field' => "meta:{$key}", 'label' => "Design {$key}", 'url' => $meta['value']];
                }
            }
        }
        return $out;
    }

    /**
     * Validate a raw items payload synchronously (used by the blocking create
     * flow before an order exists). Returns:
     *   ['ok' => bool, 'image_count' => int,
     *    'failures' => [['itemNo','field','label','url','reason'], ...]]
     */
    public function validatePayload(array $items): array
    {
        $candidates = $this->collectPayloadUrls($items);
        $failures = [];
        foreach ($candidates as $c) {
            $r = $this->validateUrl($c['url']);
            if (!$r['ok']) {
                $failures[] = array_merge($c, ['reason' => $r['reason']]);
            }
        }
        return [
            'ok' => count($failures) === 0,
            'image_count' => count($candidates),
            'failures' => $failures,
        ];
    }

    // ---- Persistence -----------------------------------------------------

    /**
     * Persist a validation result computed by the client. `$failures` is the
     * list the client built from its own per-URL image checks; each row is
     * ['order_item_id' => ?int, 'field' => string, 'url' => string,
     *  'reason' => string, 'http_status' => ?int, 'content_type' => ?string].
     * An empty list means every image passed. Idempotent — detail rows are
     * replaced wholesale, so a re-check overwrites the previous result.
     */
    public function saveResult(Order $order, int $imageCount, array $failures): OrderImageValidation
    {
        return DB::transaction(function () use ($order, $imageCount, $failures) {
            OrderImageValidationItem::where('order_id', $order->id)->delete();
            if (!empty($failures)) {
                $now = now();
                $rows = array_map(fn ($f) => [
                    'order_id'      => $order->id,
                    'order_item_id' => $f['order_item_id'] ?? null,
                    'field'         => $f['field'],
                    'url'           => (string) ($f['url'] ?? ''),
                    'reason'        => $f['reason'],
                    'http_status'   => $f['http_status'] ?? null,
                    'content_type'  => $f['content_type'] ?? null,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ], $failures);
                OrderImageValidationItem::insert($rows);
            }

            return $this->upsertSummary($order->id, [
                'checked'           => true,
                'status'            => empty($failures) ? OrderImageValidation::STATUS_PASS : OrderImageValidation::STATUS_FAIL,
                'image_count'       => $imageCount,
                'failed_count'      => count($failures),
                'checked_at'        => now(),
                'validator_version' => self::VERSION,
            ]);
        });
    }

    /**
     * Record a pass without detail rows — used by the blocking create flow,
     * which has already validated the payload server-side. All passed.
     */
    public function recordPass(Order $order, int $imageCount): OrderImageValidation
    {
        return $this->saveResult($order, $imageCount, []);
    }

    private function upsertSummary(int $orderId, array $attrs): OrderImageValidation
    {
        return OrderImageValidation::updateOrCreate(['order_id' => $orderId], $attrs);
    }
}
