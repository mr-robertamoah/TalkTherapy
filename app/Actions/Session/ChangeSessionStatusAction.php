<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionStatusEnum;

class ChangeSessionStatusAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO, string $status)
    {
        $updatedby = null;

        if (
            $status == SessionStatusEnum::in_session->value &&
            $createSessionDTO->session->status !== SessionStatusEnum::in_session->value &&
            is_null($createSessionDTO->session->updatedBy)
        ) {
            $status = SessionStatusEnum::in_session_confirmation->value;
            $updatedby = $createSessionDTO->user->is($createSessionDTO->session->addedby)
                ? $createSessionDTO->user
                : $createSessionDTO->user->counsellor;
        }

        if (
            $status == SessionStatusEnum::held->value &&
            $createSessionDTO->session->status !== SessionStatusEnum::held->value &&
            is_null($createSessionDTO->session->updatedBy)
        ) {
            $status = SessionStatusEnum::held_confirmation->value;
            $updatedby = $createSessionDTO->user->is($createSessionDTO->session->addedby)
                ? $createSessionDTO->user
                : $createSessionDTO->user->counsellor;
        }

        $createSessionDTO->session->update([
            'status' => $status
        ]);

        $createSessionDTO->session->updatedBy()->associate($updatedby);
        $createSessionDTO->session->save();
            
        // TODO dispatch update event
        return $createSessionDTO->session->refresh();
    }
}