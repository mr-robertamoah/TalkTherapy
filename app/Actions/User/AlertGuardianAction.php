<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\DTOs\GuardianAlertDTO;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

class AlertGuardianAction extends Action
{
    public function execute(GuardianAlertDTO $guardianAlertDTO)
    {
        if ($guardianAlertDTO->user->isAdult()) return;
        
        $guardians = User::query()
            ->whereWard($guardianAlertDTO->user)
            ->get();
        ds($guardians);
        if (!$guardians?->count()) return;

        Notification::send($guardians, $guardianAlertDTO->notification);
    }
}