<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\DTOs\PayoutDestinationDTO;
use App\Exceptions\PayoutException;

// TT-7.6a/SCRUM-225: KYC depth is deliberately just this -- the counsellor's EXISTING platform
// verification (from becoming a listed counsellor) plus Paystack's own account-name-match check
// at recipient-creation time (CreateCounsellorPayoutDestinationAction). No new identity-
// verification subsystem, per the user's explicit decision during SCRUM-224 review.
class EnsureCanOnboardPayoutDestinationAction extends Action
{
    public function execute(PayoutDestinationDTO $dto): void
    {
        $counsellor = $dto->user?->counsellor;

        if ($counsellor && $counsellor->isVerified()) {
            return;
        }

        throw new PayoutException('Only a verified counsellor can set up a payout destination.', 422);
    }
}
