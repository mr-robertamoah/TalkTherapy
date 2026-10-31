<?php

namespace App\Traits;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// SCRUM-300 (security review): the one way a private document (identity or license) is streamed
// back. Never trusts the stored mime -- FileService records the uploader's own claimed
// getClientMimeType() -- so a counsellor can't upload HTML/SVG declared as text/html and have it
// run on the app origin under an admin's session. Only the image types the UI previews inline are
// served inline, by server-detected type; anything else is a sandboxed download.
trait StreamsPrivateDocuments
{
    private const INLINE_DOCUMENT_TYPES = ['image/jpeg', 'image/png'];

    protected function privateDocumentResponse(string $disk, File $file): StreamedResponse
    {
        $path = $file->diskPath();
        $detectedType = Storage::disk($disk)->mimeType($path);
        $inline = in_array($detectedType, self::INLINE_DOCUMENT_TYPES, true);

        return Storage::disk($disk)->response($path, $file->name, [
            'Content-Type' => $inline ? $detectedType : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }
}
