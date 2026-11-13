<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Models\Therapy;
use App\Models\VideoConsent;

// TT-3.1e-f/SCRUM-285: the currently-valid grant (if any) for whatever
// GetCurrentVideoConsentableForTherapyAction resolves as "the relevant scope right now" -- used
// both to render the therapy page's approve/revoke UI state and, by the revoke controller
// endpoint, to find the actual VideoConsent row RevokeVideoConsentAction needs (that action takes
// a VideoConsent model, not a therapy/session id).
class GetCurrentValidVideoConsentForTherapyAction extends Action
{
    public function execute(Therapy $therapy): ?VideoConsent
    {
        $consentable = GetCurrentVideoConsentableForTherapyAction::new()->execute($therapy);

        if (! $consentable) {
            return null;
        }

        // SCRUM-313: a grant is per ward -- a therapy's is its client's.
        $ward = GetWardForVideoConsentableAction::new()->execute($therapy);

        if (! $ward) {
            return null;
        }

        return VideoConsent::query()
            ->whereValidFor($ward->id, $consentable::class, $consentable->id)
            ->first();
    }
}
