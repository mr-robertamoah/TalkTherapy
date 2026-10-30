<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Events\HandRaisedEvent;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\DB;

// TT-3.2f-f/SCRUM-323: a receive-only GroupTherapy member asks to speak. Only possible once
// already admitted to the call (no pre-join signaling -- the user's own scoping decision), and
// always self-service: the acting user is the one raising, never a client-supplied target.
class RaiseHandAction extends Action
{
    public function execute(Session $session, User $user): void
    {
        if (! $session->for instanceof GroupTherapy) {
            throw new VideoException('Raising a hand is only available in group therapy video calls.', 422);
        }

        if (! $session->for->isReceiveOnlyVideoParticipant($user)) {
            throw new VideoException('You can already speak in this video call.', 422);
        }

        $participant = $session->videoSessions()->whereNull('ended_at')->first()?->activeParticipantFor($user->id)
            ?? throw new VideoException('Join the video call before raising your hand.', 422);

        $wasRaised = DB::transaction(function () use ($participant) {
            // Backs the "at most one open raise per participant" rule the
            // vshr_participant_lowered_idx index alone can't enforce (see that migration).
            $participant = VideoSessionParticipant::query()->lockForUpdate()->find($participant->id);

            if (! $participant->isActive()) {
                throw new VideoException('Join the video call before raising your hand.', 422);
            }

            if ($participant->currentSpeakingGrant()) {
                throw new VideoException('You already have permission to speak.', 422);
            }

            if ($participant->currentHandRaise()) {
                return false;
            }

            $participant->handRaises()->create(['raised_at' => now()]);

            return true;
        });

        if ($wasRaised) {
            HandRaisedEvent::dispatch($participant->videoSession, $user->id);
        }
    }
}
