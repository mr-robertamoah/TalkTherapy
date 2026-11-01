<?php

namespace App\Actions\License;

use App\Actions\Action;
use App\Models\File;
use App\Models\License;
use App\Services\FileService;

// SCRUM-338: deletes a license's uploaded documents (file and row, via FileService so the disk is
// read from the file itself) and marks the license so the UI can say why they're gone. The License
// row is kept. $clearNumber also nulls the license number -- used for a national ID, whose number
// is itself identity data. Shared by the retention sweep, the counsellor purge and the backfill.
class PurgeLicenseDocumentsAction extends Action
{
    public function execute(License $license, bool $clearNumber = false): License
    {
        // Delete first, then detach: if a delete throws, the file is still attached and the next
        // run retries it, instead of leaving an orphan nothing will find (devops review).
        $license->files->each(function (File $file) use ($license) {
            FileService::new()->deleteFile($file);
            $license->files()->detach($file->id);
        });

        $license->documents_removed_at ??= now();

        if ($clearNumber) {
            $license->number = null;
        }

        $license->save();

        return $license->unsetRelation('files');
    }
}
