<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Serves help docs (markdown files under doc/) to authenticated sellers.
 * Lets the renderer surface a "Hướng dẫn nạp tiền" modal that mirrors the
 * runbook in doc/topup-bank-transfer.md without bundling the content into
 * every client build — single source of truth, edit-in-repo workflow.
 */
class HelpController extends Controller
{
    // Each doc has a file + display metadata so the Documents index page
    // can render a card without re-deriving titles from the markdown.
    // `audience` filters who sees the entry on the index ('seller' = all
    // logged-in sellers; 'staff' = admin/support only).
    private const DOCS = [
        'topup-guide' => [
            'file'        => 'seller-guide-topup.md',
            'title'       => 'Hướng dẫn nạp tiền',
            'description' => 'Các bước nạp ví qua Bank Transfer (VND) hoặc PingPong wire (USD), kèm FAQ.',
            'icon'        => '💳',
            'audience'    => 'seller',
        ],
        'payment-guide' => [
            'file'        => 'seller-guide-payment.md',
            'title'       => 'Hướng dẫn thanh toán đơn',
            'description' => 'Pay từng đơn, Pay all unpaid, Refund + cách đọc print_cost / shipping_cost / total_cost.',
            'icon'        => '💰',
            'audience'    => 'seller',
        ],
        'autopay-guide' => [
            'file'        => 'seller-guide-autopay.md',
            'title'       => 'Hướng dẫn Auto-pay',
            'description' => 'Bật auto-pay, chọn delay window, hành vi cron + FAQ.',
            'icon'        => '🤖',
            'audience'    => 'seller',
        ],
        'topup-runbook' => [
            'file'        => 'topup-bank-transfer.md',
            'title'       => 'Top-up runbook (admin/dev)',
            'description' => 'Cấu hình bank/PingPong + endpoint + troubleshooting cho admin.',
            'icon'        => '🛠️',
            'audience'    => 'staff',
        ],
    ];

    /**
     * List every doc the caller is allowed to see. Used by the in-app
     * Documents page so the menu is data-driven (add an entry to DOCS →
     * appears in the menu, no frontend change needed).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isStaff = $user && $user->role
            && in_array($user->role->slug ?? '', ['admin', 'support'], true);

        $items = [];
        foreach (self::DOCS as $slug => $meta) {
            if (($meta['audience'] ?? 'seller') === 'staff' && !$isStaff) continue;
            $path = base_path('doc/' . $meta['file']);
            $items[] = [
                'slug'        => $slug,
                'title'       => $meta['title'],
                'description' => $meta['description'],
                'icon'        => $meta['icon'] ?? '📄',
                'audience'    => $meta['audience'] ?? 'seller',
                'mtime'       => is_file($path) ? filemtime($path) : null,
            ];
        }
        return response()->json(['items' => $items]);
    }

    public function show(Request $request, string $slug)
    {
        if (!array_key_exists($slug, self::DOCS)) {
            return response()->json(['message' => 'Guide not found'], 404);
        }
        $meta = self::DOCS[$slug];

        // Staff-only docs are gated on read too — listing already filters
        // them out, but show() is reachable by direct slug.
        if (($meta['audience'] ?? 'seller') === 'staff') {
            $user = $request->user();
            $isStaff = $user && $user->role
                && in_array($user->role->slug ?? '', ['admin', 'support'], true);
            if (!$isStaff) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $path = base_path('doc/' . $meta['file']);
        if (!is_file($path)) {
            return response()->json(['message' => 'Guide file missing on server'], 404);
        }

        return response()->json([
            'slug'     => $slug,
            'title'    => $meta['title'],
            'markdown' => file_get_contents($path),
            // Cheap freshness signal so clients can cache aggressively.
            'mtime'    => filemtime($path),
        ]);
    }
}
