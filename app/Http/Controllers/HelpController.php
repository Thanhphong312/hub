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
    private const DOCS = [
        'topup-guide' => 'topup-bank-transfer.md',
    ];

    public function show(Request $request, string $slug)
    {
        if (!array_key_exists($slug, self::DOCS)) {
            return response()->json(['message' => 'Guide not found'], 404);
        }

        $path = base_path('doc/' . self::DOCS[$slug]);
        if (!is_file($path)) {
            return response()->json(['message' => 'Guide file missing on server'], 404);
        }

        return response()->json([
            'slug'     => $slug,
            'markdown' => file_get_contents($path),
            // Cheap freshness signal so clients can cache aggressively.
            'mtime'    => filemtime($path),
        ]);
    }
}
