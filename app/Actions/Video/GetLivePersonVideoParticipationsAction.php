<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Models\User;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Collection;

// The person's live row in each video room that hasn't ended -- the latest join, not a stale
// reconnect row (SCRUM-330) -- with its video session, session and therapy loaded. Used by
// RemovePersonFromLiveCallsAction (SCRUM-382), so a suspension (SCRUM-363) and a guardian loss
// (SCRUM-288) find calls the same way. $sessionId narrows it to one session (a re-check under that
// session's join lock).
class GetLivePersonVideoParticipationsAction extends Action
{
    public function execute(User $person, ?int $sessionId = null): Collection
    {
        return VideoSessionParticipant::query()
            ->where('participant_type', $person->getMorphClass())
            ->where('participant_id', $person->id)
            ->whereNull('left_at')
            ->whereHas('videoSession', fn ($q) => $q
                ->whereNull('ended_at')
                ->when($sessionId, fn ($q) => $q->where('session_id', $sessionId)))
            ->with('videoSession.session.for')
            ->orderByDesc('joined_at')
            ->get()
            ->unique('video_session_id')
            ->values();
    }
}
