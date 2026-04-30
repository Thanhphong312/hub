<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use App\Models\OrderItemMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ConversionController extends Controller
{
    // Source meta keys we want to convert. Their _qr counterparts hold the
    // generated overlaid image URL on Backblaze.
    private const SOURCE_KEYS = ['front', 'back', 'left', 'right', 'neck', 'special'];

    /**
     * List items belonging to the current user that have source metas
     * (e.g. "front") without their corresponding _qr meta yet.
     */
    public function pending(Request $request)
    {
        $user = $request->user();
        if (!$user->convert) {
            return response()->json(['message' => 'Convert mode is not enabled for this account'], 403);
        }

        $items = OrderItem::with(['order:id,system_id', 'metas'])
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('metas', fn ($q) => $q->whereIn('key', self::SOURCE_KEYS))
            ->orderBy('id', 'asc')
            ->get();

        $result = [];
        foreach ($items as $item) {
            $existing = $item->metas->pluck('key')->all();
            $totalMetas = count($existing);
            $pending = [];
            foreach (self::SOURCE_KEYS as $k) {
                if (in_array($k, $existing) && !in_array($k . '_qr', $existing)) {
                    $meta = $item->metas->firstWhere('key', $k);
                    if (!empty($meta->value)) {
                        $pending[] = [
                            'meta_id' => $meta->id,
                            'key' => $k,
                            'value' => $meta->value,
                        ];
                    }
                }
            }
            if (!empty($pending)) {
                $result[] = [
                    'order_item_id' => $item->id,
                    'order_id' => $item->order_id,
                    'system_id' => $item->order->system_id,
                    'total_metas' => $totalMetas,
                    'pending' => $pending,
                ];
            }
        }

        return response()->json($result);
    }

    /**
     * Receive an already-composed image from the desktop app, upload it to
     * Backblaze (B2) and persist a new <key>_qr meta on the order item.
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
            'key' => 'required|string|in:' . implode(',', self::SOURCE_KEYS),
            'image' => 'required|file|max:20480', // up to 20MB
        ]);

        $newKey = $request->key . '_qr';

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
