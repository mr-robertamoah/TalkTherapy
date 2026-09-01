<?php

namespace App\Actions\SessionScheduleProposal;

use App\Actions\Action;
use App\DTOs\SessionScheduleProposalDTO;
use App\Exceptions\SessionException;
use Carbon\Carbon;

class EnsureSessionScheduleProposalDataIsValidAction extends Action
{
    // Sanity-checks only -- a slot that passes this can still fail the real double-booking/
    // max-sessions/payment-type checks (EnsureSessionDataIsValidAction) at accept-time (TT-2.5b),
    // since availability can change between propose and accept. Re-running that full check here
    // would only prove the slot was valid *right now*, not at whatever later moment it's
    // eventually accepted.
    public function execute(SessionScheduleProposalDTO $dto)
    {
        if (! $dto->startTime || ! $dto->endTime) {
            throw new SessionException('A proposed start and end time are required.', 422);
        }

        $startTime = Carbon::parse($dto->startTime);
        $endTime = Carbon::parse($dto->endTime);

        if ($startTime->isPast()) {
            throw new SessionException('The proposed start time cannot be in the past.', 422);
        }

        if ($startTime->copy()->addMinutes(30)->greaterThan($endTime)) {
            throw new SessionException('The proposed end time must be at least 30 minutes from the start time.', 422);
        }
    }
}
