<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyStatusEnum;

class CreateGroupTherapyAction extends Action
{
    public function execute(GroupTherapyDTO $dto)
    {
        $addedby = $dto->counsellor ?: $dto->user;

        $therapy = $addedby->addedGroupTherapies()->create([
            'status' => TherapyStatusEnum::pending->value,
            'public' => $dto->public,
            'payment_type' => $dto->paymentType,
            'session_type' => $dto->sessionType,
            'allow_in_person' => $dto->allowInPerson,
            'name' => $dto->name,
            'anonymous' => $dto->anonymous,
            'allow_anyone' => $dto->allowAnyone,
            'max_sessions' => $dto->maxSessions,
            'max_users' => $dto->maxUsers,
            'max_counsellors' => $dto->maxCounsellors,
            'about' => $dto->about,
            'payment_data' => [
                'per' => $dto->per,
                'amount' => $dto->amount,
                'currency' => $dto->currency,
                'inPersonAmount' => $dto->inPersonAmount ?: '',
                'shareEqually' => $dto->shareEqually,
                'sharePercentage' => $dto->shareEqually ? null : $dto->sharePercentage
            ]
        ]);

        if ($dto->cases && count($dto->cases)) {
            $therapy->cases()->attach($dto->cases);
        }

        return $therapy->refresh();
    }
}