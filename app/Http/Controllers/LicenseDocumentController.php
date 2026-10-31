<?php

namespace App\Http\Controllers;

use App\Actions\License\EnsureUserCanViewLicenseDocumentAction;
use App\Models\File;
use App\Models\License;
use App\Traits\ResolvesExceptionResponse;
use App\Traits\StreamsPrivateDocuments;
use Illuminate\Http\Request;

// SCRUM-300: the ONLY retrieval path for a counsellor-verification document -- streams from the
// file's own private disk, never a public asset() URL. Mirrors IdentityDocumentController.
class LicenseDocumentController extends Controller
{
    use ResolvesExceptionResponse, StreamsPrivateDocuments;

    public function show(Request $httpRequest, License $license, File $file)
    {
        // The disk is whichever EnsureUserCanViewLicenseDocumentAction allows -- never an arbitrary
        // one named by the row.
        return $this->authorizedDocumentResponse(
            fn () => EnsureUserCanViewLicenseDocumentAction::new()->execute($httpRequest->user(), $license, $file),
            $file->storage,
            $file,
            'Document not found.',
        );
    }
}
