<?php

namespace App\Http\Controllers;

use App\Actions\Admin\RecordAdminContentReadAction;
use App\Actions\License\EnsureUserCanViewLicenseDocumentAction;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
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
            function () use ($httpRequest, $license, $file) {
                EnsureUserCanViewLicenseDocumentAction::new()->execute($httpRequest->user(), $license, $file);
                // SCRUM-360: an admin opening someone else's licence or ID scan.
                $owner = $license->for instanceof Counsellor ? $license->for->user : null;
                RecordAdminContentReadAction::new()->execute($httpRequest->user(), AdminContentAccess::LICENSE_DOCUMENT, $license, owner: $owner, fileId: $file->id);
            },
            $file->storage,
            $file,
            'Document not found.',
        );
    }
}
