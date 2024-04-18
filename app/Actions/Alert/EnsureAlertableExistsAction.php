<?php

namespace App\Actions\Alert;

use App\Actions\Action;
use App\DTOs\AlertServiceDTO;
use App\Exceptions\AlertException;

class EnsureAlertableExistsAction extends Action
{
    public function execute(AlertServiceDTO $alertServiceDTO)
    {
        if ($alertServiceDTO->alertable) return;

        throw new AlertException("Nothing (Therapy/Group Therapy) is provided for this alert.", 422);
    }
}