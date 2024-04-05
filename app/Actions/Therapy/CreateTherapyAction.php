<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Enums\TherapyStatusEnum;

class CreateTherapyAction extends Action
{
    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
        $therapy = $createTherapyDTO->user->addedTherapies()->create([
            'status' => TherapyStatusEnum::pending->value,
            'public' => $createTherapyDTO->public,
            'payment_type' => $createTherapyDTO->paymentType,
            'session_type' => $createTherapyDTO->sessionType,
            'allow_in_person' => $createTherapyDTO->allowInPerson,
            'name' => $createTherapyDTO->name,
            'anonymous' => $createTherapyDTO->anonymous,
            'max_sessions' => $createTherapyDTO->maxSessions,
            'background_story' => $createTherapyDTO->backgroundStory,
            'payment_data' => [
                'per' => $createTherapyDTO->per,
                'amount' => $createTherapyDTO->amount,
                'currency' => $createTherapyDTO->currency,
                'inPersonAmount' => $createTherapyDTO->inPersonAmount ?: '',
            ]
        ]);

        if ($createTherapyDTO->cases && count($createTherapyDTO->cases)) {
            $therapy->cases()->attach($createTherapyDTO->cases);
        }

        return $therapy->refresh();
    }
}