<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Exceptions\TherapyCreationDataIsNotValidException;

class EnsureTherapyDataIsValidAction extends Action
{
    public function execute(CreateTherapyDTO $createTherapyDTO)
    {
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