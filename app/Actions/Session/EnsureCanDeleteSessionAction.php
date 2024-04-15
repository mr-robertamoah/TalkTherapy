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
        if (
            (
                $createSessionDTO->user->isAdmin() ||
                (
                    $createSessionDTO->user->counsellor &&
                    $createSessionDTO->user->counsellor->is($createSessionDTO->session->addedby)
                )
            ) &&
            $createSessionDTO->session->status !== SessionTypeEnum::in_person->value
        ) return;

        throw new SessionException("You are not allowed to update session with name: {$createSessionDTO->session->name}.", 422);
    }
}