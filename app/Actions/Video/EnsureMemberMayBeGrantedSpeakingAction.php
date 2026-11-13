<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Actions\VideoConsent\HasValidVideoConsentForSessionAction;
use App\Exceptions\VideoException;
use App\Models\Session;
use App\Models\User;

// SCRUM-329 (replacing TT-3.2f-g/SCRUM-324's interim block on every minor): an under-18 in a group may be given the
// floor -- or raise a hand that could be -- only with their own guardians' consent to speak (a SPEAK-level grant for
// the group or this session, SCRUM-313) and only if they're not anonymous there (the user's decision, 2026-11-12: an
// anonymous minor watches and listens only). Anyone who doesn't need consent (an adult, a counsellor) is unaffected.
// Both Grant and RaiseHand call it; JoinVideoSessionAction asks reason() for what the call panel says.
class EnsureMemberMayBeGrantedSpeakingAction extends Action
{
    public const NEEDS_SPEAK_CONSENT = 'A parent or guardian\'s consent to speak is needed before an under-18 can speak in group video.';

    public const ANONYMOUS_MINOR = 'An under-18 who is anonymous in this group can watch and listen, but not speak.';

    public function execute(Session $session, User $member): void
    {
        if ($reason = $this->reason($session, $member)) {
            throw new VideoException($reason, 422);
        }
    }

    // Why $member may not speak in this session's call, or null if nothing stops them.
    public function reason(Session $session, User $member): ?string
    {
        $group = $session->for;

        if (! EnsureVideoIsAvailableForSessionAction::new()->groupVideoNeedsGuardianConsent($group, $member)) {
            return null;
        }

        if ($group->isMaskedToOthers($member)) {
            return self::ANONYMOUS_MINOR;
        }

        return HasValidVideoConsentForSessionAction::new()->speakingAllowedFor($session, $member) ? null : self::NEEDS_SPEAK_CONSENT;
    }
}
