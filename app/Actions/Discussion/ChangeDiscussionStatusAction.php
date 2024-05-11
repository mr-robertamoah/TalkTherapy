<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateDiscussionDTO;
use App\Enums\DiscussionStatusEnum;

class ChangeDiscussionStatusAction extends Action
{
    public function execute(CreateDiscussionDTO $createDiscussionDTO, string $status)
    {
        $updatedby = null;

        if (
            $status == DiscussionStatusEnum::in_session->value &&
            !in_array($createDiscussionDTO->discussion->status, [
                DiscussionStatusEnum::in_session->value,
                DiscussionStatusEnum::in_session_confirmation->value,
            ])
        ) $status = DiscussionStatusEnum::in_session_confirmation->value;

        if (
            $status == DiscussionStatusEnum::held->value &&
            !in_array($createDiscussionDTO->discussion->status, [
                DiscussionStatusEnum::held->value,
                DiscussionStatusEnum::held_confirmation->value,
            ])
        ) $status = DiscussionStatusEnum::held_confirmation->value;

        $updatedby = $this->getUpdatedByBasedOnStatus($createDiscussionDTO, $status);

        $createDiscussionDTO->discussion->update([
            'status' => $status
        ]);

        if ($createDiscussionDTO->discussion->updatedby) $createDiscussionDTO->discussion->updatedby()->dissociate();
        
        if ($updatedby) {
            $createDiscussionDTO->discussion->updatedby()->associate($updatedby);
        }
        
        $createDiscussionDTO->discussion->save();
            
        return $createDiscussionDTO->discussion->refresh();
    }

    private function getUpdatedByBasedOnStatus(CreateDiscussionDTO $createDiscussionDTO, String $status)
    {
        if (
            in_array($status, [
                DiscussionStatusEnum::in_session_confirmation->value,
                DiscussionStatusEnum::held_confirmation->value,
                DiscussionStatusEnum::pending->value,
            ])
        ) return $createDiscussionDTO->user->counsellor?->is($createDiscussionDTO->discussion->addedby)
            ? $createDiscussionDTO->user->counsellor
            : $createDiscussionDTO->user;

        return null;
    }
}