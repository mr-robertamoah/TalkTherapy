<?php

namespace App\Actions\Alert;

use App\Actions\Action;
use App\DTOs\AlertServiceDTO;

class CreateAlertAction extends Action
{
    public function execute(AlertServiceDTO $alertServiceDTO)
    {
        $alert = $alertServiceDTO->user->alerts()->updateOrCreate([
            'status' => $alertServiceDTO->status,
            'alertable_type' => $alertServiceDTO->alertable::class,
            'alertable_id' => $alertServiceDTO->alertable->id,
        ]);

        return $alert;
    }
}