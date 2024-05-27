<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\GuardianAlertDTO;
use Illuminate\Support\Facades\Notification;

class AlertGuardianAction extends Action
{
    public function execute(GuardianAlertDTO $guardianAlertDTO)
    {
        if ($guardianAlertDTO->user->isAdult()) return;
        
        $guardians = $guardianAlertDTO->user->guardians;

        if (!$guardians?->count()) return;

        Notification::send($guardians, $guardianAlertDTO->notification);
    }
}