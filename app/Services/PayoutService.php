<?php

namespace App\Services;

use App\Actions\Payout\CreateCounsellorPayoutDestinationAction;
use App\Actions\Payout\EnsureCanOnboardPayoutDestinationAction;
use App\Actions\Payout\TriggerCounsellorPayoutAction;
use App\DTOs\PayoutDestinationDTO;
use App\DTOs\TriggerPayoutDTO;
use App\Models\CounsellorPayout;
use App\Models\CounsellorPayoutAccount;

class PayoutService extends Service
{
    public function onboardDestination(PayoutDestinationDTO $dto): CounsellorPayoutAccount
    {
        EnsureCanOnboardPayoutDestinationAction::new()->execute($dto);

        return CreateCounsellorPayoutDestinationAction::new()->execute($dto);
    }

    public function triggerPayout(TriggerPayoutDTO $dto): CounsellorPayout
    {
        return TriggerCounsellorPayoutAction::new()->execute($dto);
    }
}
