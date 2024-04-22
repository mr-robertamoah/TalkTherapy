<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\SessionException;
use App\Models\Session;
use Carbon\Carbon;

class EnsureSessionDataIsValidAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        if ($createSessionDTO->for->isTherapy)
            return $this->validateTherapy($createSessionDTO);

        $this->validateGroupTherapy($createSessionDTO);
    }

    public function validateTherapy(CreateSessionDTO $createSessionDTO)
    {
        if ($createSessionDTO->for->status == TherapyStatusEnum::ended->value) 
            throw new SessionException("You cannot a create session for a therapy which has ended.", 422);
        
        if (
            $createSessionDTO->for?->sessionsHeld == $createSessionDTO->for?->max_sessions
        ) 
            throw new SessionException("You cannot create a session because the maximum session for this therapy has been reached.", 422);
        
        if (
            $createSessionDTO->for?->payment_type == TherapyPaymentTypeEnum::free->value &&
            $createSessionDTO->paymentType == TherapyPaymentTypeEnum::paid->value
        ) 
            throw new SessionException("You cannot create a PAID session for a FREE therapy.", 422);

        $startTime = new Carbon($createSessionDTO->startTime);
        $endTime = new Carbon($createSessionDTO->endTime);
        if (
            $startTime->addMinutes(30)->greaterThan(new Carbon($createSessionDTO->endTime))
        ) 
            throw new SessionException("The end time must be at least 30 minutes from the start time.", 422);
            
        if (
            $createSessionDTO->for->sessions()
            ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                $query->whereNot('id', $createSessionDTO->session->id);
            })
            ->whereDateIsBetweenStartAndEndTimes($startTime)
            ->exists()
        ) 
            throw new SessionException("The start time of a session cannot fall within the start and end time of other sessions.", 422);

        if (
            $createSessionDTO->for
                ->sessions()
                ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                    $query->whereNot('id', $createSessionDTO->session->id);
                })
                ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                ->exists()
        ) 
            throw new SessionException("The session must start at least 30 minutes before or after other sessions of this therapy.", 422);
        
        if (
            Session::query()
                ->whereDoesntHave('for', function ($query) use ($createSessionDTO) {
                    $query->whereParticipant($createSessionDTO->for->addedby);
                })
                ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                    $query->whereNot('id', $createSessionDTO->session->id);
                })
                ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                ->exists()
        ) 
            throw new SessionException("The user has sessions that are less than 30 minutes before or after the time for this session.", 422);

        if (
            Session::query()
                ->whereDoesntHave('for', function ($query) use ($createSessionDTO) {
                    $query->whereParticipant($createSessionDTO->for->counsellor->user);
                })
                ->when($createSessionDTO->session, function ($query) use ($createSessionDTO) {
                    $query->whereNot('id', $createSessionDTO->session->id);
                })
                ->whereIsThirtyMinituesBeforeOrAfter($startTime, $endTime)
                ->exists()
        ) 
            throw new SessionException("Counsellor for this therapy has sessions that are less than 30 minutes before or after the time for this session.", 422);

        if (
            !$createSessionDTO->for->allow_in_person &&
            $createSessionDTO->type == SessionTypeEnum::in_person->value
        ) 
            throw new SessionException("You cannot create an in-persion session for a therapy that does not allow in-person sessions.", 422);
    }

    public function validateGroupTherapy(CreateSessionDTO $createSessionDTO)
    {

    }
}