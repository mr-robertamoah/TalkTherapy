<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Contracts\VideoProviderInterface;
use App\Events\VideoParticipantRemovedEvent;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    // TT-3.2b/SCRUM-309 security-review finding: $targetUserId is a bare id, deliberately never
    // resolved against the global `users` table before authorization -- doing so previously let
    // any active counsellor distinguish "this id doesn't exist at all" (a controller-level 422)
    // from "this id exists but isn't a live participant" (this action's own no-op below), handing
    // any counsellor on any single group a system-wide user-id-existence oracle. The target is
    // now resolved ONLY from this VideoSession's own live participants, so a nonexistent id and an
    // id that's simply not in this call produce the exact same outcome.
    public function execute(Session $session, User $actingUser, int $targetUserId): void
    {
        if (! $session->for instanceof GroupTherapy) {
            throw new VideoException('Removing a specific participant is only available for group therapy video calls.', 422);
        }

        if (! $session->for->isCounsellorUser($actingUser)) {
            throw new VideoException('Only a counsellor may remove a participant from this group video call.', 422);
        }

        if ($actingUser->id === $targetUserId) {
            throw new VideoException('Use leave, not remove, to exit the video call yourself.', 422);
        }

        $videoSession = $session->videoSessions()->whereNull('ended_at')->first();

        if (! $videoSession) {
            return;
        }

        $participant = $videoSession->participants()
            ->where('participant_type', User::class)
            ->where('participant_id', $targetUserId)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return;
        }

        // Guaranteed a real, existing User -- this row only exists because that user actually
        // joined this video session at some point.
        $targetUser = $participant->participant;

        $participant->update(['left_at' => now()]);

        // Best-effort, matching endRoom()'s own contract -- a provider-side failure here must
        // never block the caller from marking the participant left locally.
        try {
            app(VideoProviderInterface::class)->removeParticipant($videoSession, $targetUser);
        } catch (Throwable $exception) {
            Log::warning('Provider-side participant removal failed; local state removes them anyway.', [
                'video_session_id' => $videoSession->id,
                'removed_user_id' => $targetUserId,
                'exception' => $exception->getMessage(),
            ]);
        }

        VideoParticipantRemovedEvent::dispatch($videoSession, $targetUserId);
    }
}
