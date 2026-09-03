<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\Exceptions\PayoutException;
use App\Services\SettingsService;

// TT-7.6c/SCRUM-227: the minimum-payout threshold applies identically whether the counsellor
// triggers their own payout or an admin triggers it on their behalf -- deliberately no admin
// bypass, per the user's decision (one enforcement path, not two, for the same money-movement
// operation).
class EnsurePayoutMeetsMinimumThresholdAction extends Action
{
    public function execute(int $availableAmount, string $currency): void
    {
        $minimum = SettingsService::new()->getMinimumPayoutAmount($currency);

        if ($availableAmount >= $minimum) {
            return;
        }

        throw new PayoutException("The available balance does not yet meet the minimum payout threshold for {$currency}.", 422);
    }
}
