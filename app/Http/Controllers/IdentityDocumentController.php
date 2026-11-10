<?php

namespace App\Http\Controllers;

use App\Actions\Admin\RecordAdminContentReadAction;
use App\Actions\User\EnsureUserCanViewIdentityDocumentAction;
use App\Models\AdminContentAccess;
use App\Models\File;
use App\Models\Request as ModelsRequest;
use App\Models\User;
use App\Traits\StreamsPrivateDocuments;
use Illuminate\Http\Request;

// TT-4.11a/SCRUM-302: the ONLY retrieval path for an identity-verification document -- streams
// from the private 'identity_documents' disk, never a public asset() URL (see that disk's own
// config/filesystems.php comment, and the pre-existing gap this deliberately avoids, SCRUM-300).
class IdentityDocumentController extends Controller
{
    use StreamsPrivateDocuments;

    public function show(Request $httpRequest, ModelsRequest $request, File $file)
    {
        return $this->authorizedDocumentResponse(
            function () use ($httpRequest, $request, $file) {
                EnsureUserCanViewIdentityDocumentAction::new()->execute($httpRequest->user(), $request, $file);
                // SCRUM-360: an admin opening someone else's identity document.
                $owner = $request->for instanceof User ? $request->for : ($request->from instanceof User ? $request->from : null);
                RecordAdminContentReadAction::new()->execute($httpRequest->user(), AdminContentAccess::IDENTITY_DOCUMENT, $request, owner: $owner, fileId: $file->id);
            },
            'identity_documents',
            $file,
            'Document not found.',
        );
    }
}
