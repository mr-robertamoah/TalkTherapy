<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;

class CreateSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        $addedby = $createSessionDTO->user->isAdmin() 
            ? $createSessionDTO->user
            : $createSessionDTO->user->counsellor;
            
        $session = $addedby->addedSessions()->create([
            'status' => SessionStatusEnum::pending->value,
            'payment_type' => $createSessionDTO->paymentType,
            'type' => $createSessionDTO->type,
            'name' => $createSessionDTO->name,
            'about' => $createSessionDTO->about,
            'landmark' => $createSessionDTO->landmark,
            'longitude' => $createSessionDTO->longitude,
            'latitude' => $createSessionDTO->latitude,
            'start_time' => $createSessionDTO->startTime,
            'end_time' => $createSessionDTO->endTime,
        ]);

        $session->for()->associate($createSessionDTO->for);
        $session->save();

        if ($createSessionDTO->cases && count($createSessionDTO->cases)) {
            $session->cases()->attach($createSessionDTO->cases);
        }

        if ($createSessionDTO->for->status == TherapyStatusEnum::pending->value)
            $createSessionDTO->for->update(['status' => TherapyStatusEnum::in_session->value]);

        return $session->refresh();
    }
}