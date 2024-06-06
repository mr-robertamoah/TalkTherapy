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
            !in_array($createSessionDTO->session->status, [
                SessionStatusEnum::in_session->value,
                SessionStatusEnum::in_session_confirmation->value,
            ])
        ) $status = SessionStatusEnum::in_session_confirmation->value;

        if (
            $status == SessionStatusEnum::held->value &&
            !in_array($createSessionDTO->session->status, [
                SessionStatusEnum::held->value,
                SessionStatusEnum::held_confirmation->value,
            ])
        ) $status = SessionStatusEnum::held_confirmation->value;

        $updatedby = $this->getUpdatedByBasedOnStatus($createSessionDTO, $status);

        $createSessionDTO->session->update([
            'status' => $status
        ]);

        if ($createSessionDTO->session->updatedby) $createSessionDTO->session->updatedby()->dissociate();
        
        if ($updatedby) {
            $createSessionDTO->session->updatedby()->associate($updatedby);
        }
        
        $createSessionDTO->session->save();
            
        return $createSessionDTO->session->refresh();
    }

    private function getUpdatedByBasedOnStatus(CreateSessionDTO $createSessionDTO, String $status)
    {
        if (
            in_array($status, [
                SessionStatusEnum::in_session_confirmation->value,
                SessionStatusEnum::held_confirmation->value,
                SessionStatusEnum::pending->value,
                SessionStatusEnum::abandoned->value,
            ])
        ) return $createSessionDTO->user->counsellor?->is($createSessionDTO->session->addedby)
            ? $createSessionDTO->user->counsellor
            : $createSessionDTO->user;

        return null;
    }
}