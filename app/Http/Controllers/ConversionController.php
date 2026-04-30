<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ConversionController extends Controller
{
    /** Source meta keys we want to convert. */
    private const SOURCE_KEYS = ['front', 'back', 'left', 'right', 'neck', 'special'];

    /** Regex matching every produced _qr meta key, e.g. front_qr, front_qr_1, back_qr_3. */
    private const QR_KEY_REGEX_SQL = '_qr(_[0-9]+)?$';

    /**
     * Build a list of _qr metas that still need to be generated for the
     * authenticated user's orders.
     *
     * For an item with source keys S = [front, back, ...] in canonical order
     * and quantity Q, total = |S| * Q. Each source key gets Q output metas
     * named "<source>_qr_<copy>" with copy=1..Q, and a global running index
     * 1..total (front copies first, then back, etc.).
     */
    public function pending(Request $request)
    {
        $user = $request->user();
        if (!$user->convert) {
            return response()->json(['message' => 'Convert mode is not enabled for this account'], 403);
        }

        // Iterate at the order level so we can compute the accessory summary
        // (combining all items of the same order) once per order.
        $orders = Order::with([
                'items.metas',
                'items.accessoryPrice',
            ])
            ->where('user_id', $user->id)
            ->whereHas('items.metas', fn ($q) => $q->whereIn('key', self::SOURCE_KEYS))
            ->orderBy('id', 'asc')
            ->get();

        $result = [];
        foreach ($orders as $order) {
            $accessorySummary = $this->computeAccessorySummary($order);
            foreach ($order->items as $item) {
                $existingByKey = $item->metas->keyBy('key');

                $sourceKeysWithValue = [];
                foreach (self::SOURCE_KEYS as $sk) {
                    $m = $existingByKey->get($sk);
                    if ($m && !empty($m->value)) {
                        $sourceKeysWithValue[] = $sk;
                    }
                }
                if (empty($sourceKeysWithValue)) continue;

                $qty = max(1, (int) ($item->quantity ?? 1));
                $total = count($sourceKeysWithValue) * $qty;

                $globalIdx = 0;
                $pending = [];
                foreach ($sourceKeysWithValue as $sk) {
                    $sourceMeta = $existingByKey->get($sk);
                    for ($copy = 1; $copy <= $qty; $copy++) {
                        $globalIdx++;
                        $targetKey = "{$sk}_qr_{$copy}";
                        if ($existingByKey->has($targetKey)) continue;
                        $pending[] = [
                            'source_meta_id' => $sourceMeta->id,
                            'source_key' => $sk,
                            'source_value' => $sourceMeta->value,
                            'target_key' => $targetKey,
                            'copy' => $copy,
                            'total_copies' => $qty,
                            'index' => $globalIdx,
                            'total' => $total,
                        ];
                    }
                }

                if (!empty($pending)) {
                    $result[] = [
                        'order_item_id' => $item->id,
                        'order_id' => $order->id,
                        'system_id' => $order->system_id,
                        'accessory_summary' => $accessorySummary,
                        'quantity' => $qty,
                        'total_metas' => $total,
                        'pending' => $pending,
                    ];
                }
            }
        }

        return response()->json($result);
    }

    /**
     * Per-order accessory summary, e.g. "2W-2R-1N":
     *   one segment per order item, "<quantity><letter>" where letter is the
     *   first uppercase character of accessoryPrice.style (fallback to
     *   accessory_code) or "N" when no accessory was selected.
     */
    private function computeAccessorySummary($order): string
    {
        $parts = [];
        foreach ($order->items as $item) {
            $qty = max(1, (int) ($item->quantity ?? 1));
            $letter = 'N';
            if ($item->accessoryPrice) {
                $style = trim((string) ($item->accessoryPrice->style ?? ''));
                $code = trim((string) ($item->accessoryPrice->accessory_code ?? ''));
                if ($style !== '') {
                    $letter = strtoupper(substr($style, 0, 1));
                } elseif ($code !== '') {
                    $letter = strtoupper(substr($code, 0, 1));
                }
            }
            $parts[] = "{$qty}{$letter}";
        }
        return implode('-', $parts);
    }

    /**
     * Receive an already-composed image from the desktop app, upload it to B2
     * and persist (or update) the OrderItemMeta with the requested target_key.
     */
    public function uploadQrMeta(Request $request, OrderItem $item)
    {
        $user = $request->user();
        if (!$user->convert) {
            return response()->json(['message' => 'Convert mode is not enabled for this account'], 403);
        }
        if ($item->order->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'key' => ['required', 'string', 'regex:/^(front|back|left|right|neck|special)_qr(_\d+)?$/'],
            'image' => 'required|file|max:20480',
        ]);

        $newKey = $request->key;
        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $path = "qr/{$item->id}/{$newKey}_" . time() . '.' . $ext;

        Storage::disk('b2')->put($path, file_get_contents($file->getPathname()), 'public');
        $base = rtrim(config('filesystems.disks.b2.url') ?? '', '/');
        $url = $base . '/' . ltrim($path, '/');

        $meta = OrderItemMeta::updateOrCreate(
            ['order_item_id' => $item->id, 'key' => $newKey],
            ['value' => $url]
        );

        return response()->json([
            'message' => 'QR meta saved',
            'meta' => $meta,
        ], 201);
    }
}
