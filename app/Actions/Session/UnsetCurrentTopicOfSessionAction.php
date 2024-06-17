<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Models\Session;
use App\Models\TherapyTopicSession;

class UnsetCurrentTopicOfSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO) : Session
    {
        TherapyTopicSession::query()
            ->whereSession($createSessionDTO->session)
            ->whereCurrent()
            ->update(['current' => false]);

        return $createSessionDTO->session->refresh();
    }
}