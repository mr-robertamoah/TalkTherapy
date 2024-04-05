<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\TherapyCreationDataIsNotValidException;

class EnsureTherapyDataIsValidAction extends Action
{
    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
        if (
            $createTherapyDTO->therapy &&
            $createTherapyDTO->therapy->status == TherapyStatusEnum::ended->value
        ) 
            throw new TherapyCreationDataIsNotValidException("You cannot update a therapy which has ended.", 422);
        
        if (
            $createTherapyDTO->therapy?->sessionsHeld &&
            (
                $createTherapyDTO->therapy->session_type !== $createTherapyDTO->sessionType ||
                $createTherapyDTO->therapy->payment_type !== $createTherapyDTO->paymentType
            )
        ) 
            throw new TherapyCreationDataIsNotValidException("You cannot change payment type (PAID, FREE) or session type (ONCE, PERIODIC) once there have been at least one session held.", 422);

        if (
            $createTherapyDTO->sessionType == TherapySessionTypeEnum::periodic->value &&
            (!$createTherapyDTO->maxSessions || $createTherapyDTO->maxSessions < 2)
        ) 
            throw new TherapyCreationDataIsNotValidException("Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2.", 422);

        if (
            $createTherapyDTO->paymentType == TherapyPaymentTypeEnum::paid->value &&
            !($createTherapyDTO->amount && $createTherapyDTO->currency && $createTherapyDTO->per)
        ) 
            throw new TherapyCreationDataIsNotValidException("Amount, currency and per what? All of these are required since you selected PAID payment type.", 422);

        if (
            $createTherapyDTO->inPersonAmount && $createTherapyDTO->amount &&
            $createTherapyDTO->inPersonAmount < $createTherapyDTO->amount
        ) 
            throw new TherapyCreationDataIsNotValidException("Amount in-person session cannot be less than amount for online session.", 422);
        
        if (
            $createTherapyDTO->paymentType == TherapyPaymentTypeEnum::free->value &&
            !$createTherapyDTO->public
        ) 
            throw new TherapyCreationDataIsNotValidException("FREE payment types requires that you set public to true.", 422);

        if (
            $createTherapyDTO->paymentType == TherapyPaymentTypeEnum::paid->value &&
            $createTherapyDTO->sessionType == TherapySessionTypeEnum::once->value &&
            $createTherapyDTO->per !== TherapyPerPaymentEnum::therapy->value
        ) 
            throw new TherapyCreationDataIsNotValidException("Since ONCE and PAID have been selected for session and payment types respectively, the per amount should be THERAPY.", 422);
    }
}