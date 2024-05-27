<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\GetGuardianshipDTO;
use App\Notifications\GuardianshipRemovedNotification;

class DeleteGuardianshipAction extends Action
{
    public function execute(GetGuardianshipDTO $getGuardianshipDTO)
    {
        $getGuardianshipDTO->guardianship->delete();

        $getGuardianshipDTO->guardianship->ward->notify(
            new GuardianshipRemovedNotification($getGuardianshipDTO->guardianship->guardian)
        );
    }
}