<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\Exceptions\SessionException;

class EnsureCanCreateSessionAction extends Action
{
    public function execute(CreateSessionDTO|CreateTherapyTopicDTO $createSessionDTO, String $what = 'session')
    {
        if (
            $createSessionDTO->user->isAdmin() ||
            (
                $createSessionDTO->user->counsellor &&
                $createSessionDTO->therapy->isCounsellor($createSessionDTO->user->counsellor)
            )
            // TODO not banned from creating or suspending from the app
        ) return;

        throw new SessionException("You are not allowed to create a {$what} for therapy with name: {$createSessionDTO->therapy->name}.", 422);
    }
}