<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;

class DeleteSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        $createSessionDTO->session->starreable()->delete();

        $createSessionDTO->session->delete();

        // TODO dispatch event to frontend

        return $createSessionDTO->session->refresh();
    }
}