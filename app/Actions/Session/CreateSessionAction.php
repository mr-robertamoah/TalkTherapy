<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use Carbon\Carbon;

class CreateSessionAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        $addedby = $createSessionDTO->user->isAdmin()
            ? $createSessionDTO->user
            : $createSessionDTO->user->counsellor;

        $inPerson = $createSessionDTO->type === SessionTypeEnum::in_person->value;

        $session = $addedby->addedSessions()->create([
            'status' => SessionStatusEnum::pending->value,
            'payment_type' => $createSessionDTO->paymentType,
            'type' => $createSessionDTO->type,
            'name' => $createSessionDTO->name,
            'about' => $createSessionDTO->about,
            // SCRUM-443: only an in-person session has a place; an online one stores none (it used to be 0,0).
            'landmark' => $inPerson && filled($createSessionDTO->landmark) ? trim($createSessionDTO->landmark) : null,
            'longitude' => $inPerson ? $createSessionDTO->longitude : null,
            'latitude' => $inPerson ? $createSessionDTO->latitude : null,
            'start_time' => (new Carbon($createSessionDTO->startTime))->utc(),
            'end_time' => (new Carbon($createSessionDTO->endTime))->utc(),
        ]);

        $session->for()->associate($createSessionDTO->for);
        $session->save();

        if ($createSessionDTO->cases && count($createSessionDTO->cases)) {
            $session->cases()->attach($createSessionDTO->cases);
        }

        if ($createSessionDTO->for->status == TherapyStatusEnum::pending->value) {
            $createSessionDTO->for->update(['status' => TherapyStatusEnum::in_session->value]);
        }

        return $session->refresh();
    }
}
