<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\File\DeleteAttachedFilesAction;
use App\Enums\RequestStatusEnum;
use App\Models\Request;
use Carbon\CarbonInterface;

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
            ->where('responded_at', '<=', now()->subDays(self::retentionDays()))
            ->get()
            ->each(fn (Request $request) => DeleteAttachedFilesAction::new()->execute($request, 'identity document retention'));
    }

    // SCRUM-50: when this sweep deletes $request's documents (the same rule as execute()), for "What we hold about
    // you" -- null while it is still waiting for a decision (never swept). A date in the past: the next run.
    public static function deletesAt(Request $request): ?CarbonInterface
    {
        if ($request->status === RequestStatusEnum::pending->value || ! $request->responded_at) {
            return null;
        }

        return $request->responded_at->copy()->addDays(self::retentionDays());
    }

    private static function retentionDays(): int
    {
        return (int) config('identity_verification.document_retention_days');
    }
}
