<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\TherapyCreationDataIsNotValidException;

class EnsureTherapyDataIsValidAction extends Action
{
    public function execute(CreateTherapyDTO|GroupTherapyDTO $dto)
    {
        $therapy = $dto::class == GroupTherapyDTO::class ? $dto->groupTherapy : $dto->therapy;

        if (
            $therapy &&
            $therapy->status == TherapyStatusEnum::ended->value
        ) 
            throw new TherapyCreationDataIsNotValidException("You cannot update a therapy which has ended.", 422);
        
        if (
            $therapy?->sessionsHeld &&
            (
                ($dto->sessionType && $therapy->session_type !== $dto->sessionType) ||
                ($dto->paymentType && $therapy->payment_type !== $dto->paymentType)
            )
        ) 
            throw new TherapyCreationDataIsNotValidException("You cannot change payment type (PAID, FREE) or session type (ONCE, PERIODIC) once there have been at least one session held.", 422);

        if (
            $dto->sessionType == TherapySessionTypeEnum::periodic->value &&
            (!$dto->maxSessions || $dto->maxSessions < 2)
        ) 
            throw new TherapyCreationDataIsNotValidException("Since PERIODIC has been selected for the session type, the maximum number of sessions must be at least 2.", 422);

        if (
            $dto->paymentType == TherapyPaymentTypeEnum::paid->value &&
            !($dto->amount && $dto->currency && $dto->per)
        ) 
            throw new TherapyCreationDataIsNotValidException("Amount, currency and per what? All of these are required since you selected PAID payment type.", 422);

        if (
            $dto->inPersonAmount && $dto->amount &&
            $dto->inPersonAmount < $dto->amount
        ) 
            throw new TherapyCreationDataIsNotValidException("Amount in-person session cannot be less than amount for online session.", 422);
        
        if (
            $dto->paymentType == TherapyPaymentTypeEnum::free->value &&
            !$dto->public
        ) 
            throw new TherapyCreationDataIsNotValidException("FREE payment types requires that you make therapy PUBLIC.", 422);

        if (
            $dto->paymentType == TherapyPaymentTypeEnum::paid->value &&
            $dto->sessionType == TherapySessionTypeEnum::once->value &&
            $dto->per !== TherapyPerPaymentEnum::therapy->value
        ) 
            throw new TherapyCreationDataIsNotValidException("Since ONCE and PAID have been selected for session and payment types respectively, the amount should be per THERAPY.", 422);
    
        if (!(
            $dto::class == GroupTherapyDTO::class
        )) return;

        $maxCounsellors = env('GROUP_THERAPY_MAX_COUNSELLORS', 10);
        if ($dto->maxCounsellors > $maxCounsellors) {
            throw new TherapyCreationDataIsNotValidException("Your counsellors cannot be more than {$maxCounsellors}.", 422);
        }

        $maxUsers = env('GROUP_THERAPY_MAX_USERS', 50);
        if ($dto->maxUsers > $maxUsers) {
            throw new TherapyCreationDataIsNotValidException("Your users cannot be more than {$maxUsers}.", 422);
        }

        if (!(
            $dto->paymentType == TherapyPaymentTypeEnum::paid->value
        )) return;

        if (
            $dto->counsellor &&
            !$dto->shareEqually &&
            (
                !$dto->sharePercentage ||
                $dto->sharePercentage > 100 ||
                $dto->sharePercentage < 40
            )
        ) 
            throw new TherapyCreationDataIsNotValidException("The share to counsellors cannot be more than 100% or below 40%.", 422);

        if (
            !$dto->counsellor &&
            $dto->shareEqually
        ) 
            throw new TherapyCreationDataIsNotValidException("As a user, you cannot share equally with counsellors at the momemnt. You can have a maximum of 30%.", 422);
        
        if (
            !$dto->counsellor &&
            (
                !$dto->sharePercentage ||
                $dto->sharePercentage < 70
            )
        ) 
            throw new TherapyCreationDataIsNotValidException("As a user, your share of group therapy cannot go beyound 30%. Hence the share percentage for counsellors must be 70% or higher.", 422);
    }
}