<?php

namespace App\Actions\Alert;

use App\Actions\Action;
use App\DTOs\AlertServiceDTO;

class DeleteAlertAction extends Action
{
    public function execute(AlertServiceDTO $alertServiceDTO)
    {
        return $alertServiceDTO->alert->delete();
    }
}