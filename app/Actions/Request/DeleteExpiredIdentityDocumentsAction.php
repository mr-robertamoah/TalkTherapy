<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\File\DeleteAttachedFilesAction;
use App\Enums\RequestStatusEnum;
use App\Models\Request;

// TT-4.11a/SCRUM-302: an identity-verification document (age/identity verification) is only
// ever kept for a configurable window (30 days by default) after its owning Request has been
// DECIDED (accepted/rejected) -- a still-PENDING request's document is never swept, regardless
// of age, since it hasn't been reviewed yet. Deletes both the stored file and its DB row, not
// just one -- an orphaned File row pointing at an already-deleted path is exactly the kind of
// stale reference File::url/getUrlFor() would otherwise happily resolve to a 404 forever.
// SCRUM-362: through the shared DeleteAttachedFilesAction, which reads each file's disk from
// $file->storage and keeps a file attached (retried next run) if its blob couldn't be deleted.
// SCRUM-376: moved out of AppService.
class DeleteExpiredIdentityDocumentsAction extends Action
{
    public function execute(): void
    {
        Request::query()
            ->whereHas('files')
            ->where('status', '!=', RequestStatusEnum::pending->value)
            // SCRUM-362: from when the request was decided, not its last touch.
            ->where('responded_at', '<=', now()->subDays(config('identity_verification.document_retention_days')))
            ->get()
            ->each(fn (Request $request) => DeleteAttachedFilesAction::new()->execute($request, 'identity document retention'));
    }
}
