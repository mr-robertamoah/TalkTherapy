<?php

namespace App\Actions\Alert;

use App\Actions\Action;
use App\DTOs\AlertServiceDTO;

class CreateAlertAction extends Action
{
    public function execute(AlertServiceDTO $alertServiceDTO)
    {
        $alert = $alertServiceDTO->user->alerts()->create([
            'status' => $alertServiceDTO->status
        ]);

        $alert->alertable()->associate($alertServiceDTO->alertable);
        $alert->save();

        return $alert;
    }
}