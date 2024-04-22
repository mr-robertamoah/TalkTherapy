<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureCanEndSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        if (
            $createSessionDTO->user->isAdmin() ||
            (
                now()->greaterThan($createSessionDTO->session->end_time) &&
                (
                    $createSessionDTO->for->isUser($createSessionDTO->user) ||
                    (
                        $createSessionDTO->user->counsellor && 
                        $createSessionDTO->for->isCounsellor($createSessionDTO->user->counsellor)
                    )
                )
            )
        ) return;

        throw new SessionException("You are not allowed to end session with name: {$createSessionDTO->session->name}. Also ensure that it is past the end time of the session.", 422);
    }
}