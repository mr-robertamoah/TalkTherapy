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

        // SCRUM-31: once verified, a counsellor whose verification has ended may still change where
        // they're paid -- their current clients carry on, and so do their earnings. Not after a
        // revocation (the one place it differs from ending): a verification revoked as possibly
        // fraudulent mustn't be able to redirect payouts. Their existing destination keeps working.
        if ($counsellor && $counsellor->wasEverVerified() && is_null($counsellor->verification_revoked_at) && $counsellor->payoutAccount()->exists()) {
            return;
        }

        throw new PayoutException('Only a verified counsellor can set up a payout destination.', 422);
    }
}
