<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Exceptions\TherapyException;
use App\Models\GroupTherapy;
use App\Models\VideoSession;

// SCRUM-332: refuses switching a group to anonymous while one of its video calls is live. Everyone
// in that call has already seen the members' real names (and, for speakers, faces) -- downgrading
// capabilities mid-call couldn't honestly undo that, so the change waits for the call to end, after
// which every new join is masked and audio-only (JoinVideoSessionAction / GrantSpeakingPermissionAction).
// Turning anonymity OFF, or leaving it unchanged, is always allowed. Not atomic with the update
// itself -- a join landing in between gets one unmasked token; accepted at this traffic level.
class EnsureGroupTherapyCanBecomeAnonymousAction extends Action
{
    public function execute(GroupTherapy $groupTherapy, ?bool $requestedAnonymous): void
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
            throw new TherapyException('End the current video call before making this group anonymous.', 422);
        }
    }
}
