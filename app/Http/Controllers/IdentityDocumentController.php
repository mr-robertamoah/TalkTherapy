<?php

namespace App\Http\Controllers;

use App\Actions\User\EnsureUserCanViewIdentityDocumentAction;
use App\Models\File;
use App\Models\Request as ModelsRequest;
use App\Traits\ResolvesExceptionResponse;
use App\Traits\StreamsPrivateDocuments;
use Illuminate\Http\Request;
use Throwable;

// TT-4.11a/SCRUM-302: the ONLY retrieval path for an identity-verification document -- streams
// from the private 'identity_documents' disk, never a public asset() URL (see that disk's own
// config/filesystems.php comment, and the pre-existing gap this deliberately avoids, SCRUM-300).
class IdentityDocumentController extends Controller
{
    use ResolvesExceptionResponse, StreamsPrivateDocuments;

    public function show(Request $httpRequest, ModelsRequest $request, File $file)
    {
        try {
            EnsureUserCanViewIdentityDocumentAction::new()->execute($httpRequest->user(), $request, $file);

            // SCRUM-300: server-detected type and hardened headers, shared with license documents.
            return $this->privateDocumentResponse('identity_documents', $file);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json([
                'status' => false,
                'error' => $message,
            ], $status);
        }
    }
}
