<?php

namespace App\Actions\Alert;

use App\Actions\Action;
use App\DTOs\AlertServiceDTO;
use App\Exceptions\AlertException;

class EnsureAlertableExistsAction extends Action
{
    public function execute(AlertServiceDTO $alertServiceDTO)
    {
        // SCRUM-352: only a participant waits for a therapy's alerts -- the same answer for a
        // therapy you're not in as for one that doesn't exist.
        $alertable = $alertServiceDTO->alertable;
        if ($alertable && $alertServiceDTO->user && $alertable->isParticipant($alertServiceDTO->user)) {
            return;
        }

        throw new AlertException('Nothing (Therapy/Group Therapy) is provided for this alert.', 422);
    }
}
