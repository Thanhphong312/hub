<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class QrController extends Controller
{
    public function show(Request $request, string $systemId)
    {
        $order = Order::with([
            'items.metas',
            'items.productVariant.product',
            'items.accessoryPrice.accessory',
            'items.accessoryPrice.tier',
            'address',
            'user',
        ])->where('system_id', $systemId)->first();

        if (!$order) {
            throw new NotFoundHttpException('Order not found');
        }

        $user = $request->user();
        // Sellers can only see their own orders
        if ($user->hasRole('seller') && $order->user_id !== $user->id) {
            abort(403, 'Forbidden');
        }

        return view('qr.show', [
            'order' => $order,
            'user' => $user,
        ]);
    }

    public function updateStatus(Request $request, string $systemId)
    {
        $request->validate(['status' => 'required|in:shipped,onhold']);

        $order = Order::where('system_id', $systemId)->first();
        if (!$order) {
            throw new NotFoundHttpException('Order not found');
        }

        $statusValue = $request->status === 'shipped' ? Order::STATUS_SHIPPED : Order::STATUS_ONHOLD;
        $data = ['status' => $statusValue];
        if ($statusValue === Order::STATUS_SHIPPED) {
            $data['completed_time'] = now();
        }

        $order->update($data);

        // XHR (from Ship & Print button) gets JSON; classic form posts redirect.
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'status' => $request->status]);
        }

        return redirect()->route('qr.show', ['systemId' => $systemId])
            ->with('status_message', 'Order marked as ' . $request->status . '.');
    }

    /**
     * Render a minimal page that loads the proxied label and auto-prints it.
     */
    public function printPage(Request $request, string $systemId)
    {
        $order = $this->findOrderForUser($request, $systemId);
        abort_unless($order->shipping_label, 404, 'No shipping label on this order.');

        return view('qr.print', [
            'order' => $order,
            'imageUrl' => route('qr.label-image', ['systemId' => $systemId]),
        ]);
    }

    /**
     * Stream the shipping label bytes from its remote URL via the server so the
     * browser sees it as same-origin (no CORS, no Drive HTML wrapper, no
     * Dropbox login page). Cached on disk, keyed by the source URL.
     */
    public function labelImage(Request $request, string $systemId)
    {
        $order = $this->findOrderForUser($request, $systemId);
        $url = $this->resolveDirectUrl($order->shipping_label);
        abort_unless($url, 404, 'No shipping label URL.');

        $cacheKey = 'qr-labels/' . md5($url);

        if (!Storage::disk('local')->exists($cacheKey)) {
            $resp = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 BullStart-QrProxy'])
                ->get($url);

            if (!$resp->successful()) {
                abort(502, 'Could not fetch label from source (HTTP ' . $resp->status() . ').');
            }

            $body = $resp->body();
            $contentType = $resp->header('Content-Type') ?: $this->guessContentType($body);

            // Sanity check: Drive sometimes returns an HTML "virus scan" page
            // for large files instead of the binary. In that case, follow the
            // confirm link once.
            if (str_contains($contentType, 'text/html') && str_contains($body, 'confirm=')) {
                if (preg_match('/confirm=([0-9A-Za-z_-]+)/', $body, $m) && preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $idMatch)) {
                    $confirmUrl = "https://drive.google.com/uc?export=download&confirm={$m[1]}&id={$idMatch[1]}";
                    $resp = Http::timeout(15)->get($confirmUrl);
                    if ($resp->successful()) {
                        $body = $resp->body();
                        $contentType = $resp->header('Content-Type') ?: $this->guessContentType($body);
                    }
                }
            }

            Storage::disk('local')->put($cacheKey, $body);
            Storage::disk('local')->put($cacheKey . '.type', $contentType);
        }

        $body = Storage::disk('local')->get($cacheKey);
        $contentType = Storage::disk('local')->exists($cacheKey . '.type')
            ? Storage::disk('local')->get($cacheKey . '.type')
            : $this->guessContentType($body);

        return response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline; filename="label-' . $systemId . '"',
        ]);
    }

    private function findOrderForUser(Request $request, string $systemId): Order
    {
        $order = Order::where('system_id', $systemId)->first();
        if (!$order) {
            throw new NotFoundHttpException('Order not found');
        }

        $user = $request->user();
        if ($user && $user->hasRole('seller') && $order->user_id !== $user->id) {
            abort(403, 'Forbidden');
        }

        return $order;
    }

    /**
     * Rewrite share-style URLs (Drive `/file/d/<id>/view`, Dropbox `?dl=0`)
     * into URLs that respond with raw bytes. Direct image URLs pass through.
     */
    private function resolveDirectUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        // Google Drive — extract file id, hit the direct download endpoint.
        if (preg_match('#(?:/file/d/|[?&]id=)([a-zA-Z0-9_-]+)#', $url, $m)) {
            return 'https://drive.google.com/uc?export=download&id=' . $m[1];
        }

        // Dropbox — share links serve HTML wrapper unless `?dl=1` (or
        // dl.dropboxusercontent.com is used). Force raw bytes.
        if (str_contains($url, 'dropbox.com/')) {
            $url = str_replace('www.dropbox.com', 'dl.dropboxusercontent.com', $url);
            $url = preg_replace('/([?&])dl=0(&|$)/', '$1dl=1$2', $url);
            if (!str_contains($url, 'dl=1')) {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'dl=1';
            }
            return $url;
        }

        return $url;
    }

    /**
     * Sniff PNG / JPEG / PDF magic bytes when the source server didn't set
     * Content-Type (or set a wrong one).
     */
    private function guessContentType(string $body): string
    {
        if (str_starts_with($body, "\x89PNG")) return 'image/png';
        if (str_starts_with($body, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($body, 'GIF8')) return 'image/gif';
        if (str_starts_with($body, '%PDF-')) return 'application/pdf';
        if (str_starts_with($body, 'RIFF') && str_contains(substr($body, 0, 12), 'WEBP')) return 'image/webp';
        return 'application/octet-stream';
    }
}
