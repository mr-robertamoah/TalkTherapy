<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Exceptions\IdentityDocumentAccessDeniedException;
use App\Models\File;
use App\Models\Request as ModelsRequest;
use App\Models\User;

// TT-4.11a/SCRUM-302: gates the one and only retrieval path for an identity-verification
// document -- an admin (any reviewer), or the request's own `for` user (the person who
// submitted it, viewing their own upload back). Deliberately narrower than
// EnsureUserCanRespondToRequestAction's own broader "who can act on this request" shape, since
// viewing a raw identity document is a stricter operation than merely approving/rejecting.
//
// Security-review finding: both failure modes (file not attached to this request, and
// authorized-but-wrong-person) throw the SAME exception/status -- a caller with no right to a
// request must never be able to distinguish "this file/request pair doesn't exist" from "it
// does, but you can't see it," since even that alone confirms a specific user submitted an
// identity/age-verification document, on a mental-health platform.
class EnsureUserCanViewIdentityDocumentAction extends Action
{
    public function execute(User $user, ModelsRequest $request, File $file): void
    {
        $isAuthorized = $user->isAdmin() || ($request->for instanceof User && $request->for->is($user));
        $isAttachedToRequest = $request->files()->where('files.id', $file->id)->exists();

        if ($isAuthorized && $isAttachedToRequest) {
            return;
        }

        throw new IdentityDocumentAccessDeniedException('Document not found.', 404);
    }
}
