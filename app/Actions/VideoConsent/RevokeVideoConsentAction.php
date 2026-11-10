<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Enums\VideoConsentRevocationReasonEnum;
use App\Exceptions\VideoConsentException;
use App\Models\User;
use App\Models\VideoConsent;

// TT-3.1e-c/SCRUM-282: any ONE guardian of the ward may revoke -- symmetric with
// GrantVideoConsentAction's own "any one guardian is sufficient, no unanimity" rule (own design
// decision, not the granting guardian specifically -- guardians act as equals here, matching the
// grant side).
class RevokeVideoConsentAction extends Action
{
    public function execute(User $guardian, VideoConsent $consent): VideoConsent
    {
        if (! $guardian->isGuardianOf($consent->ward)) {
            throw new VideoConsentException('You are not a guardian of this client.', 422);
        }

        return InvalidateVideoConsentAction::new()->execute(
            $consent,
            VideoConsentRevocationReasonEnum::guardian_action,
            $guardian,
        );
    }
}
