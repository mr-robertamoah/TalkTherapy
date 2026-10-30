<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Models\Session;
use App\Models\User;

// TT-3.2f-h/SCRUM-325: the granted member's own client reports real mic/camera activity while
// their permission is open, keeping the idle sweep (AppService::expireIdleSpeakingGrants()) from
// reverting them. Self-service only, and only ever touches the caller's OWN open grant on their
// live join. Anything else -- no call, not in it, no open grant -- is a silent no-op: a ping can
// only delay losing a permission the caller already holds, never create or extend anyone else's.
class RecordSpeakingActivityAction extends Action
{
    public function execute(Session $session, User $user): void
    {
        // One conditional UPDATE, not select-then-update -- a revoke committing in between could
        // otherwise write last_activity_at onto a closed row, which the migration reserves for open
        // rows only.
        $session->videoSessions()->whereNull('ended_at')->first()
            ?->activeParticipantFor($user->id)
            ?->speakingGrants()
            ->whereNull('revoked_at')
            ->update(['last_activity_at' => now()]);
    }
}
