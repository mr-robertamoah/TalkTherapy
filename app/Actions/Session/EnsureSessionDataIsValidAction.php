<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\SessionException;
use Carbon\Carbon;

class EnsureSessionDataIsValidAction extends Action
{
    public function execute(CreateSessionDTO $createSessionDTO)
    {
        if ($createSessionDTO->therapy->status == TherapyStatusEnum::ended->value) 
            throw new SessionException("You cannot a session to a therapy which has ended.", 422);
        
        if (
            $createSessionDTO->therapy?->sessionsHeld == $createSessionDTO->therapy?->max_sessions
        ) 
            throw new SessionException("You cannot create a session because the maximum session for this therapy has been reached.", 422);
        
        if (
            $createSessionDTO->therapy?->payment_type == TherapyPaymentTypeEnum::free->value &&
            $createSessionDTO->paymentType == TherapyPaymentTypeEnum::paid->value
        ) 
            throw new SessionException("You cannot create a PAID session for a FREE therapy.", 422);

        $startTime = new Carbon($createSessionDTO->startTime);
        if (
            !$startTime->addMinutes(30)->lessThanOrEqualTo(new Carbon($createSessionDTO->endTime))
        ) 
            throw new SessionException("The end time must be at least 30 minutes from the start time.", 422);

        if (
            $createSessionDTO->therapy->sessions()->whereDateFallsBetween($startTime->toTimeString())->exists()
        ) 
            throw new SessionException("The start time of a session cannot fall within the start and end time of other sessions.", 422);

        if (
            $createSessionDTO->therapy->sessions()->whereIsNot30MinituesBeforeOrAfter($startTime->toTimeString())->exists()
        ) 
            throw new SessionException("The session must start at least 30 minutes before or after other sessions.", 422);

        if (
            !$createSessionDTO->therapy->allow_in_person &&
            $createSessionDTO->type == SessionTypeEnum::in_person->value
        ) 
            throw new SessionException("You cannot create an in-persion session for a therapy that does not allow in-person sessions.", 422);
    }
}