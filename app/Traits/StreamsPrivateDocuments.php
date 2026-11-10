<?php

namespace App\Traits;

use App\Models\File;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

// SCRUM-300 (security review): the one way a private file (identity, license, chat attachment) is
// streamed back. Never trusts the stored mime -- FileService records the uploader's own claimed
// getClientMimeType() -- so nobody can upload HTML/SVG declared as text/html and have it run on the
// app origin under someone else's session. Only the given, non-scriptable media types are served
// inline, by server-detected type; anything else is a sandboxed download.
//
// SCRUM-336: also the shared body of every private-document controller (authorize, then stream,
// every failure as JSON), and a BinaryFileResponse rather than a plain stream, so HTTP Range works:
// video/audio seeking, and Safari won't play media at all without it. The class using this must
// also use ResolvesExceptionResponse.
trait StreamsPrivateDocuments
{
    protected const INLINE_DOCUMENT_TYPES = ['image/jpeg', 'image/png'];

    // SCRUM-336: chat attachments also preview video/audio in the UI. Still never SVG or HTML.
    protected const INLINE_MEDIA_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'video/quicktime',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/mp4',
    ];

    // $authorize throws (with an HTTP status code) to refuse; $notFound is the same message the
    // route's own ->missing() uses, so a file that's gone from disk is indistinguishable from any
    // other refusal. The disk is only ever read after $authorize has accepted it.
    protected function authorizedDocumentResponse(
        Closure $authorize,
        string $disk,
        File $file,
        string $notFound,
        array $inlineTypes = self::INLINE_DOCUMENT_TYPES,
    ): BinaryFileResponse|JsonResponse {
        try {
            $authorize();

            $storage = Storage::disk($disk);
            $path = $file->diskPath();

            if (! $storage->exists($path)) {
                return response()->json(['status' => false, 'error' => $notFound], 404);
            }

            $detectedType = $storage->mimeType($path);
            $inline = in_array($detectedType, $inlineTypes, true);

            $response = response()->file($storage->path($path), [
                'Content-Type' => $inline ? $detectedType : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'Cache-Control' => 'private, no-store',
            ]);
            $response->setContentDisposition($inline ? 'inline' : 'attachment', $file->name);

            return $response;
        } catch (Throwable $th) {
            $status = $this->statusFor($th);

            return response()->json([
                'status' => false,
                'error' => $this->messageFor($th, $status),
            ], $status);
        }
    }
}
