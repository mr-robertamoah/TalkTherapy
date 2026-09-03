<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\Exceptions\PayoutException;
use App\Models\Counsellor;

class EnsureCounsellorHasPayoutDestinationAction extends Action
{
    public function execute(Counsellor $counsellor): void
    {
        if ($counsellor->payoutAccount) {
            return;
        }

        throw new PayoutException('This counsellor has not set up a payout destination yet.', 422);
    }
}
