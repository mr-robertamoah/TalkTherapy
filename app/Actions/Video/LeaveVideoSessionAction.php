<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Models\Session;
use App\Models\User;

// TT-3.1a/SCRUM-274: a self-leave -- records this user's own departure without ending the room
// for the other participant, and deliberately never touches Session.status (see TT-3.1d, which
// builds the actual disconnect/reconnect UX on top of this). Ending the whole room for everyone
// is EndVideoSessionAction's own, separate job.
class LeaveVideoSessionAction extends Action
{
    public function execute(Session $session, User $user): void
    {
        $videoSession = $session->videoSessions()->whereNull('ended_at')->first();

        if (! $videoSession) {
            return;
        }

        $videoSession->participants()
            ->where('participant_type', User::class)
            ->where('participant_id', $user->id)
            ->whereNull('left_at')
            ->latest('joined_at')
            ->first()
            ?->update(['left_at' => now()]);
    }
}
