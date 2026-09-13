<?php

namespace App\Http\Controllers;

use App\Actions\User\EnsureUserCanViewIdentityDocumentAction;
use App\Models\File;
use App\Models\Request as ModelsRequest;
use App\Traits\ResolvesExceptionResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

// TT-4.11a/SCRUM-302: the ONLY retrieval path for an identity-verification document -- streams
// from the private 'identity_documents' disk, never a public asset() URL (see that disk's own
// config/filesystems.php comment, and the pre-existing gap this deliberately avoids, SCRUM-300).
class IdentityDocumentController extends Controller
{
    use ResolvesExceptionResponse;

    public function show(Request $httpRequest, ModelsRequest $request, File $file)
    {
        try {
            EnsureUserCanViewIdentityDocumentAction::new()->execute($httpRequest->user(), $request, $file);

            $path = (strlen($file->path) ? $file->path.'/' : '').$file->name;

            return Storage::disk('identity_documents')->response($path, $file->name, [
                'Content-Type' => $file->mime,
            ]);
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
