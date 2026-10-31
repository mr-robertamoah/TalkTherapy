<?php

namespace App\Http\Controllers;

use App\Actions\User\EnsureUserCanViewIdentityDocumentAction;
use App\Models\File;
use App\Models\Request as ModelsRequest;
use App\Traits\ResolvesExceptionResponse;
use App\Traits\StreamsPrivateDocuments;
use Illuminate\Http\Request;

// TT-4.11a/SCRUM-302: the ONLY retrieval path for an identity-verification document -- streams
// from the private 'identity_documents' disk, never a public asset() URL (see that disk's own
// config/filesystems.php comment, and the pre-existing gap this deliberately avoids, SCRUM-300).
class IdentityDocumentController extends Controller
{
    use ResolvesExceptionResponse, StreamsPrivateDocuments;

    public function show(Request $httpRequest, ModelsRequest $request, File $file)
    {
        return $this->authorizedDocumentResponse(
            fn () => EnsureUserCanViewIdentityDocumentAction::new()->execute($httpRequest->user(), $request, $file),
            'identity_documents',
            $file,
            'Document not found.',
        );
    }
}
