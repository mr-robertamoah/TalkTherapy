<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Exceptions\TherapyException;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\VideoSession;

// SCRUM-332: refuses switching a group to anonymous while one of its video calls is live. Everyone
// in that call has already seen the members' real names (and, for speakers, faces) -- downgrading
// capabilities mid-call couldn't honestly undo that, so the change waits for the call to end, after
// which every new join is masked and audio-only (JoinVideoSessionAction / GrantSpeakingPermissionAction).
// Turning anonymity OFF, or leaving it unchanged, is always allowed. Atomic with the update
// itself: GroupTherapyService runs this and the update under a lock on the group's Session rows,
// which JoinVideoSessionAction also takes (SCRUM-334). Also used for a 1:1 Therapy (security review:
// it had no guard at all, and the counsellor has already seen the client's name in a live call).
class EnsureGroupTherapyCanBecomeAnonymousAction extends Action
{
    public function execute(Therapy|GroupTherapy $groupTherapy, ?bool $requestedAnonymous): void
    {
        if (! $requestedAnonymous || $groupTherapy->anonymous) {
            return;
        }

        // "Live" = an open epoch someone is still IN. An epoch everyone simply left stays open
        // (only an explicit End closes it), and the creator -- the person most likely to hit this
        // -- can't end group calls, so counting abandoned epochs would block this forever.
        $hasLiveVideoCall = VideoSession::query()
            ->whereNull('ended_at')
            ->whereIn('session_id', $groupTherapy->sessions()->select('id'))
            ->whereHas('participants', fn ($query) => $query->whereNull('left_at'))
            ->exists();

        if ($hasLiveVideoCall) {
            $what = $groupTherapy instanceof GroupTherapy ? 'group' : 'therapy';

            throw new TherapyException("End the current video call before making this {$what} anonymous.", 422);
        }
    }
}
