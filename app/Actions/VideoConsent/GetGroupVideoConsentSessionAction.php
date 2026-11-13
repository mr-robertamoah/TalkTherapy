<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Enums\SessionStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Session;

// SCRUM-313: the session a guardian's "this session only" consent is for -- the group's live session, or else its
// next one still to come. Worked out here, never taken from the request, so a grant can't be pointed at another
// group's session or one long past.
class GetGroupVideoConsentSessionAction extends Action
{
    public function execute(GroupTherapy $group): ?Session
    {
        return $group->sessions()->whereActive()->orderBy('start_time')->first()
            ?? $group->sessions()
                ->where('status', SessionStatusEnum::pending->value)
                ->where('start_time', '>', now())
                ->orderBy('start_time')
                ->first();
    }
}
