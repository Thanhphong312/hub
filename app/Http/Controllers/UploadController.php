<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Generic file upload proxy → Backblaze B2.
 *
 * Browser SPAs can't talk to B2 directly (CORS), so the web app POSTs
 * multipart file here; the server streams it to the `b2` disk and returns
 * the public URL. The desktop apps still upload directly via their main
 * process (window.electronAPI.s3Upload) and skip this endpoint.
 */
class UploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            // 50MB ceiling — invoices/labels/mockups rarely exceed a few MB.
            // Bump if a legitimate use case needs more.
            'file'   => 'required|file|max:51200',
            // Subfolder under the date-scoped root. Sanitized below.
            'folder' => 'nullable|string|max:64',
        ]);

        $file = $request->file('file');
        $folder = $this->sanitizeFolder($request->input('folder', 'uploads'));

        $ext = $file->getClientOriginalExtension();
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBase = $this->sanitizeFilename($base) ?: 'file';
        $filename = $safeBase . ($ext ? '.' . strtolower($ext) : '');
        $datePrefix = now()->format('Y-m-d');
        $path = "uploads/{$datePrefix}/{$folder}/" . time() . '_' . $filename;

        Storage::disk('b2')->put($path, file_get_contents($file->getPathname()), 'public');

        $base = rtrim(config('filesystems.disks.b2.url') ?? '', '/');
        $url = $base . '/' . ltrim($path, '/');

        return response()->json([
            'url'          => $url,
            'key'          => $path,
            'size'         => $file->getSize(),
            'content_type' => $file->getMimeType(),
        ], 201);
    }

    private function sanitizeFolder(string $folder): string
    {
        $folder = preg_replace('#[^a-zA-Z0-9/_-]#', '', $folder) ?: 'uploads';
        return trim($folder, '/');
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    }
}
