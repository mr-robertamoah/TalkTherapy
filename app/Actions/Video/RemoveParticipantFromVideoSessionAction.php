<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Events\VideoParticipantRemovedEvent;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Support\VideoJoinLock;
use Illuminate\Contracts\Cache\LockTimeoutException;

// TT-3.2b/SCRUM-309: ejects one specific participant without ending the room for anyone else --
// distinct from EndVideoSessionAction's whole-room teardown. GroupTherapy-only: a 1:1 Therapy's
// video has exactly 2 participants, so ending the call already covers "get the other person out"
// and there is no third party to remove. Authorization mirrors TT-3.2d's own EndVideoSessionAction
// restriction (active counsellor only) -- an ordinary member was never let into the room in the
// first place (EnsureVideoIsAvailableForSessionAction), and a counsellor removing another
// counsellor is allowed (moderation is a team-wide capability, not host-only, per TT-3.2's own
// "every active counsellor gets host credentials" scoping).
class RemoveParticipantFromVideoSessionAction extends Action
{
    public const COUNSELLOR_ONLY = 'Only a counsellor may remove a participant from this group video call.';

    // TT-3.2b/SCRUM-309 security-review finding: $targetUserId is a bare id, deliberately never
    // resolved against the global `users` table before authorization -- doing so previously let
    // any active counsellor distinguish "this id doesn't exist at all" (a controller-level 422)
    // from "this id exists but isn't a live participant" (this action's own no-op below), handing
    // any counsellor on any single group a system-wide user-id-existence oracle. The target is
    // now resolved ONLY from this VideoSession's own live participants, so a nonexistent id and an
    // id that's simply not in this call produce the exact same outcome.
    // SCRUM-315: false when the provider didn't confirm the eject (see EjectVideoParticipantAction).
    public function execute(Session $session, User $actingUser, int $targetUserId): bool
    {
        if (! $session->for instanceof GroupTherapy) {
            throw new VideoException('Removing a specific participant is only available for group therapy video calls.', 422);
        }

        if (! $session->for->isCounsellorUser($actingUser)) {
            throw new VideoException(self::COUNSELLOR_ONLY, 422);
        }

        if ($actingUser->id === $targetUserId) {
            throw new VideoException('Use leave, not remove, to exit the video call yourself.', 422);
        }

        // TT-3.3b/SCRUM-459 (security re-review): under the target's join lock, as
        // RemovePersonFromLiveCallsAction does -- so a removal and that person's concurrent
        // (automatic) rejoin can't interleave: the rejoin either lands first and is the row ejected
        // here, or comes after and sees the removal. A busy lock is the join's own "try again" 409.
        try {
            return VideoJoinLock::run($session->id, $targetUserId, function () use ($session, $actingUser, $targetUserId) {
                $videoSession = $session->videoSessions()->whereNull('ended_at')->first();

                if (! $videoSession) {
                    return true;
                }

                $participant = $videoSession->activeParticipantFor($targetUserId);

                if (! $participant) {
                    $this->markRemovedWhileDisconnected($videoSession, $targetUserId);

                    return true;
                }

                return EjectVideoParticipantAction::new()->execute($videoSession, $participant, $actingUser);
            });
        } catch (LockTimeoutException) {
            throw new VideoException('This person is joining the video call right now. Please try again in a moment.', 409);
        }
    }

    // TT-3.3b/SCRUM-459 (security re-review LOW-1): someone whose connection dropped and whose
    // automatic rejoin is between attempts has no open row (a failed attempt closes its own), yet
    // their browser is about to rejoin. A removal then has nobody to eject, but must still count:
    // their latest row in this call is marked removed (left_at untouched), which refuses the next
    // automatic rejoin (JoinVideoSessionAction::ensureRejoinAllowed()), and the same
    // participant-removed broadcast as an eject tells a browser still listening to stop. Someone
    // with no row in this call at all gets exactly the old no-op -- nothing to tell apart.
    private function markRemovedWhileDisconnected(VideoSession $videoSession, int $targetUserId): void
    {
        $latestRow = $videoSession->participantRowsFor($targetUserId)
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestRow) {
            return;
        }

        $latestRow->update(['removed_at' => now()]);

        VideoParticipantRemovedEvent::dispatch($videoSession, $targetUserId);
    }
}
