<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\User;

// TT-3.2f-g/SCRUM-324: interim hard-block, no consent flow -- a minor GroupTherapy member can watch
// (receive-only join is unconditional) but can never be granted speaking permission, nor raise a
// hand that could never be granted. Mirrors TT-3.2a's own minor-creator block in
// EnsureVideoIsAvailableForSessionAction. Group-scoped guardian consent (SCRUM-313) doesn't exist
// yet -- SCRUM-329 replaces THIS one action once it does (both Grant and RaiseHand call it).
class EnsureMemberMayBeGrantedSpeakingAction extends Action
{
    public function execute(GroupTherapy $groupTherapy, User $member): void
    {
        if ($groupTherapy->memberIsMinor($member)) {
            throw new VideoException('Speaking permission is not yet available to a minor member in group therapy video.', 422);
        }
    }
}
