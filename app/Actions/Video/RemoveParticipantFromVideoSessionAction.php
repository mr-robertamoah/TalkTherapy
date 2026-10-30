<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Contracts\VideoProviderInterface;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Events\HandLoweredEvent;
use App\Events\VideoParticipantRemovedEvent;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionHandRaise;
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

        $participant = $videoSession->activeParticipantFor($targetUserId);

        if (! $participant) {
            return;
        }

        // Guaranteed a real, existing User -- this row only exists because that user actually
        // joined this video session at some point.
        $targetUser = $participant->participant;

        // TT-3.2f-i/SCRUM-326: clear their speaking state BEFORE ejecting, while their row is still
        // the live one -- so the shared revoke pushes receive-only to the provider first. The eject
        // below is best-effort; if it fails, the member is at least no longer able to speak. Uses
        // the SAME revoke as a counsellor's manual one (architect requirement), with its own reason.
        // $actingUser recorded as the revoker: unlike the idle sweep, removal IS a counsellor's own
        // action, and the grant row is the only place that counsellor gets recorded.
        $revoke = RevokeSpeakingPermissionAction::new();
        $revoked = $revoke->revokeAllFor($videoSession, $participant, SpeakingPermissionRevocationReasonEnum::participant_removed, $actingUser);

        // Security-review finding: a grant only on a STALE row is closed locally, never muting the
        // live join -- but a reconnected Chime attendee may still carry that grant's capability, so
        // mute explicitly (non-fatal for this reason) rather than rely on the best-effort eject alone.
        if ($revoked->isNotEmpty() && ! $revoked->contains('video_session_participant_id', $participant->id)) {
            $revoke->muteAtProvider($videoSession, $targetUser, SpeakingPermissionRevocationReasonEnum::participant_removed);
        }

        $loweredHand = $this->lowerHandsFor($videoSession, $targetUserId);

        // Every open row, not just the live one -- a stale row left open by a reconnect (SCRUM-330)
        // would otherwise keep the removed user counted as "in the call", grantable, and able to
        // raise a hand again.
        $videoSession->participantRowsFor($targetUserId)->whereNull('left_at')->update(['left_at' => now()]);

        // Second pass, now that every row is left: catches a grant/raise that landed between the
        // cleanup above and marking left (this can't sit behind the participant-row lock grant/raise
        // take, since the mute must happen while the row is still live). Grants on left rows are
        // closed locally only; the eject below removes the person regardless.
        $revoke->revokeAllFor($videoSession, $participant, SpeakingPermissionRevocationReasonEnum::participant_removed, $actingUser);
        $loweredHand = $this->lowerHandsFor($videoSession, $targetUserId) || $loweredHand;

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

        if ($loweredHand) {
            HandLoweredEvent::dispatch($videoSession, $targetUserId);
        }

        VideoParticipantRemovedEvent::dispatch($videoSession, $targetUserId);
    }

    private function lowerHandsFor(VideoSession $videoSession, int $userId): bool
    {
        return (bool) VideoSessionHandRaise::query()
            ->whereNull('lowered_at')
            ->whereIn('video_session_participant_id', $videoSession->participantRowsFor($userId)->select('id'))
            ->update(['lowered_at' => now()]);
    }
}
