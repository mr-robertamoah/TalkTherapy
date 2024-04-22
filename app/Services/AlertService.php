<?php

namespace App\Services;

use App\Actions\Alert\CreateAlertAction;
use App\Actions\Alert\DeleteAlertAction;
use App\Actions\Alert\EnsureAlertableExistsAction;
use App\Actions\User\EnsureUserExistsAction;
use App\DTOs\AlertServiceDTO;

class AlertService extends Service
{
    public function waitingForAlert(AlertServiceDTO $alertServiceDTO)
    {
        EnsureUserExistsAction::new()->execute($alertServiceDTO->user);

        EnsureAlertableExistsAction::new()->execute($alertServiceDTO);

        return CreateAlertAction::new()->execute($alertServiceDTO);
    }

    public function appDeleteAlert(AlertServiceDTO $alertServiceDTO)
    {
        return DeleteAlertAction::new()->execute($alertServiceDTO);
    }
}