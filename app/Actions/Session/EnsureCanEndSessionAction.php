<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Exceptions\SessionException;

class EnsureCanEndSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        $user = $createSessionDTO->user;

        // SCRUM-339: participation first, with the same refusal as every other session action --
        // only then the end-time rule, which is safe to explain to a participant.
        $isParticipant = $user->isAdmin()
            || $createSessionDTO->for->isUser($user)
            || ($user->counsellor && $createSessionDTO->for->isCounsellor($user->counsellor));

        if (! $isParticipant) {
            throw new SessionException('You are not allowed to do that with this session.', 422);
        }

        if (! $user->isAdmin() && ! now()->greaterThan($createSessionDTO->session->end_time)) {
            throw new SessionException('You can only end this session once its end time has passed.', 422);
        }
    }
}
