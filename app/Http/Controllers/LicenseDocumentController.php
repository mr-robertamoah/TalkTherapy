<?php

namespace App\Http\Controllers;

use App\Actions\License\EnsureUserCanViewLicenseDocumentAction;
use App\Models\File;
use App\Models\License;
use App\Traits\ResolvesExceptionResponse;
use App\Traits\StreamsPrivateDocuments;
use Illuminate\Http\Request;
use Throwable;

// SCRUM-300: the ONLY retrieval path for a counsellor-verification document -- streams from the
// file's own private disk, never a public asset() URL. Mirrors IdentityDocumentController.
class LicenseDocumentController extends Controller
{
    use ResolvesExceptionResponse, StreamsPrivateDocuments;

    public function show(Request $httpRequest, License $license, File $file)
    {
        try {
            EnsureUserCanViewLicenseDocumentAction::new()->execute($httpRequest->user(), $license, $file);

            // The disk is whichever EnsureUserCanViewLicenseDocumentAction just allowed -- never
            // an arbitrary one named by the row.
            return $this->privateDocumentResponse($file->storage, $file);
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
