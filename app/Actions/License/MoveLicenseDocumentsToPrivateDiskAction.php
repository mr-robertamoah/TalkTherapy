<?php

namespace App\Actions\License;

use App\Actions\Action;
use App\Actions\File\MovePublicFilesToPrivateDiskAction;

// SCRUM-300: license documents' move off the public disk. The logic now lives in
// MovePublicFilesToPrivateDiskAction (shared with SCRUM-336's message attachments); this stays
// because the already-run 2026_10_31_100000 migration refers to it.
class MoveLicenseDocumentsToPrivateDiskAction extends Action
{
    public function execute(): int
    {
        return MovePublicFilesToPrivateDiskAction::new()->execute('licenses', 'licenses', 'license_documents');
    }
}
