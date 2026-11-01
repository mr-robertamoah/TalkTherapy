<?php

namespace App\Actions\License;

use App\Actions\Action;
use App\Actions\File\DeleteAttachedFilesAction;
use App\Models\License;

// SCRUM-338: deletes a license's uploaded documents (file and row, via FileService so the disk is
// read from the file itself) and marks the license so the UI can say why they're gone. The License
// row is kept. $clearNumber also nulls the license number -- used for a national ID, whose number
// is itself identity data. Shared by the retention sweep, the counsellor purge and the backfill.
class PurgeLicenseDocumentsAction extends Action
{
    public function execute(License $license, bool $clearNumber = false): License
    {
        // SCRUM-362: the shared action deletes each blob before detaching it, so a failed delete
        // stays attached and the next run retries it.
        DeleteAttachedFilesAction::new()->execute($license, 'license retention');

        $license->documents_removed_at ??= now();

        if ($clearNumber) {
            $license->number = null;
        }

        $license->save();

        return $license;
    }
}
