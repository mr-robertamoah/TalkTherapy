<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Events\HandLoweredEvent;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\DB;

// TT-3.2f-f/SCRUM-323: self-service withdrawal of your own raised hand. A safe no-op when there's
// nothing open to lower -- including after a counsellor's grant, which already closed the raise
// (GrantSpeakingPermissionAction), so "only before a grant is acted on" holds by construction.
class LowerHandAction extends Action
{
    public function execute(Session $session, User $user): void
    {
        $participant = $session->videoSessions()->whereNull('ended_at')->first()?->activeParticipantFor($user->id);

        if (! $participant) {
            return;
        }

        $wasLowered = DB::transaction(function () use ($participant) {
            $participant = VideoSessionParticipant::query()->lockForUpdate()->find($participant->id);

            return (bool) $participant->handRaises()->whereNull('lowered_at')->update(['lowered_at' => now()]);
        });

        if ($wasLowered) {
            HandLoweredEvent::dispatch($participant->videoSession, $user->id);
        }
    }
}
