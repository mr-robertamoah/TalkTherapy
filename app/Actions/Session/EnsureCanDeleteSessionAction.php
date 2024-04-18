<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionTypeEnum;
use App\Exceptions\SessionException;

class EnsureCanDeleteSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        if ($createSessionDTO->session->isNotDeleteable())
            throw new SessionException("{$createSessionDTO->session->name} session cannot be deleted because it is either about to start or has started.", 422);

        if (
            (
                $createSessionDTO->user->isAdmin() ||
                (
                    $createSessionDTO->user->counsellor &&
                    $createSessionDTO->user->counsellor->is($createSessionDTO->session->addedby)
                )
            )
        ) return;

        throw new SessionException("You are not allowed to delete session with name: {$createSessionDTO->session->name}.", 422);
    }
}