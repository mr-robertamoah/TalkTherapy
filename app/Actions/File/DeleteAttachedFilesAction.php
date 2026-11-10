<?php

namespace App\Actions\File;

use App\Actions\Action;
use App\Models\File;
use App\Services\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

// SCRUM-362: the one way to delete every file attached to a model (a license's documents, an
// identity-verification request's scan, a deleted report's evidence). For each file:
// FileService::deleteFile (the stored blob, then its row); only if that worked, detach it. A blob
// that couldn't be deleted stays attached, so a sweep's next run retries it (a caller that deletes
// the owner straight after must report what's left). Each deletion is logged with ids only (never a
// path or file name). Returns how many files were deleted.
class DeleteAttachedFilesAction extends Action
{
    public function execute(Model $owner, string $reason, string $relation = 'files'): int
    {
        $deleted = 0;

        $owner->{$relation}()->get()->each(function (File $file) use ($owner, $reason, $relation, &$deleted) {
            if (! FileService::new()->deleteFile($file)) {
                Log::warning('Could not delete a stored file; it will be retried.', $this->context($file, $owner, $reason));

                return;
            }

            $owner->{$relation}()->detach($file->id);
            $deleted++;

            Log::info('File deleted.', $this->context($file, $owner, $reason));
        });

        $owner->unsetRelation($relation);

        return $deleted;
    }

    private function context(File $file, Model $owner, string $reason): array
    {
        return [
            'file' => $file->id,
            'owner' => class_basename($owner),
            'ownerId' => $owner->getKey(),
            'disk' => $file->storage,
            'reason' => $reason,
        ];
    }
}
