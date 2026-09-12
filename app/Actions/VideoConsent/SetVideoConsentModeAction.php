<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Enums\VideoConsentModeEnum;
use App\Exceptions\VideoConsentException;
use App\Models\Therapy;
use App\Models\User;

// TT-3.1e-b/SCRUM-281: settable by EITHER the therapy's assigned counsellor OR any guardian of
// the minor client -- deliberately NOT the client themselves (user's own decision, 2026-09-11).
// Mode switches are prospective-only (TT-3.1e-a) -- this action only ever updates the setting
// itself, it never touches any existing VideoConsent row.
class SetVideoConsentModeAction extends Action
{
    public function execute(Therapy $therapy, User $actor, string $mode): Therapy
    {
        $wardResolver = GetWardForVideoConsentableAction::new();
        $ward = $wardResolver->execute($therapy);

        if (! $ward) {
            throw new VideoConsentException('This therapy has no minor client -- video consent mode does not apply.', 422);
        }

        // TT-4.10b/SCRUM-291: was a live $ward->isAdult() re-check -- now prefers the therapy's
        // own stable client_was_minor_at_creation snapshot (TT-4.10a), closing the self-editable-
        // dob bypass SCRUM-287 found.
        if (! $wardResolver->isMinor($therapy)) {
            throw new VideoConsentException('Video consent mode only applies to a minor client.', 422);
        }

        $isCounsellor = (bool) ($actor->counsellor && $therapy->isCounsellor($actor->counsellor));
        $isGuardian = $actor->isGuardianOf($ward);

        if (! $isCounsellor && ! $isGuardian) {
            throw new VideoConsentException('You are not allowed to set this therapy\'s video consent mode.', 422);
        }

        if (! in_array($mode, VideoConsentModeEnum::values())) {
            throw new VideoConsentException('Invalid video consent mode.', 422);
        }

        $therapy->update(['video_consent_mode' => $mode]);

        return $therapy;
    }
}
