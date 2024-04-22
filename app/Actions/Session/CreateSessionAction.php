<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use Carbon\Carbon;

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
            'landmark' => !!$createSessionDTO->landmark ? $createSessionDTO->landmark : null,
            'longitude' => !!$createSessionDTO->longitude ? $createSessionDTO->longitude : null,
            'latitude' => $createSessionDTO->latitude,
            'start_time' => (new Carbon($createSessionDTO->startTime))->utc(),
            'end_time' => (new Carbon($createSessionDTO->endTime))->utc(),
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