<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Contracts\VideoProviderInterface;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Events\HandLoweredEvent;
use App\Events\VideoParticipantRemovedEvent;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionHandRaise;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\Log;
use Throwable;

// Takes one live participant out of a video room: their speaking grants and raised hands are
// cleared, every open row of theirs is marked left, and the provider is asked to eject them
// (best effort). Extracted from RemoveParticipantFromVideoSessionAction (TT-3.2b/SCRUM-309) so a
// suspension (SCRUM-363) removes people the same way. $actor is the counsellor removing them, or
// null when the system does (recorded as the revoker of any grant). It checks nothing itself:
// callers authorize first.
class EjectVideoParticipantAction extends Action
{
    public function execute(VideoSession $videoSession, VideoSessionParticipant $participant, ?User $actor = null): void
    {
        $userId = (int) $participant->participant_id;

        // Guaranteed a real, existing User -- this row only exists because that user actually
        // joined this video session at some point.
        $targetUser = $participant->participant;

        // TT-3.2f-i/SCRUM-326: clear their speaking state BEFORE ejecting, while their row is still
        // the live one -- so the shared revoke pushes receive-only to the provider first. The eject
        // below is best-effort; if it fails, the member is at least no longer able to speak. Uses
        // the SAME revoke as a counsellor's manual one (architect requirement), with its own reason.
        // $actor recorded as the revoker: unlike the idle sweep, removal IS a counsellor's own
        // action, and the grant row is the only place that counsellor gets recorded.
        $revoke = RevokeSpeakingPermissionAction::new();
        $revoked = $revoke->revokeAllFor($videoSession, $participant, SpeakingPermissionRevocationReasonEnum::participant_removed, $actor);

        // Security-review finding: a grant only on a STALE row is closed locally, never muting the
        // live join -- but a reconnected Chime attendee may still carry that grant's capability, so
        // mute explicitly (non-fatal for this reason) rather than rely on the best-effort eject alone.
        if ($revoked->isNotEmpty() && ! $revoked->contains('video_session_participant_id', $participant->id)) {
            $revoke->muteAtProvider($videoSession, $targetUser, SpeakingPermissionRevocationReasonEnum::participant_removed);
        }

        $loweredHand = $this->lowerHandsFor($videoSession, $userId);

        // Every open row, not just the live one -- a stale row left open by a reconnect (SCRUM-330)
        // would otherwise keep the removed user counted as "in the call", grantable, and able to
        // raise a hand again.
        $videoSession->participantRowsFor($userId)->whereNull('left_at')->update(['left_at' => now()]);

        // Second pass, now that every row is left: catches a grant/raise that landed between the
        // cleanup above and marking left (this can't sit behind the participant-row lock grant/raise
        // take, since the mute must happen while the row is still live). Grants on left rows are
        // closed locally only; the eject below removes the person regardless.
        $revoke->revokeAllFor($videoSession, $participant, SpeakingPermissionRevocationReasonEnum::participant_removed, $actor);
        $loweredHand = $this->lowerHandsFor($videoSession, $userId) || $loweredHand;

        // Best-effort, matching endRoom()'s own contract -- a provider-side failure here must
        // never block the caller from marking the participant left locally.
        try {
            app(VideoProviderInterface::class)->removeParticipant($videoSession, $targetUser);
        } catch (Throwable $exception) {
            Log::warning('Provider-side participant removal failed; local state removes them anyway.', [
                'video_session_id' => $videoSession->id,
                'removed_user_id' => $userId,
                'exception' => $exception->getMessage(),
            ]);
        }

        if ($loweredHand) {
            HandLoweredEvent::dispatch($videoSession, $userId);
        }

        VideoParticipantRemovedEvent::dispatch($videoSession, $userId);
    }

    private function lowerHandsFor(VideoSession $videoSession, int $userId): bool
    {
        return (bool) VideoSessionHandRaise::query()
            ->whereNull('lowered_at')
            ->whereIn('video_session_participant_id', $videoSession->participantRowsFor($userId)->select('id'))
            ->update(['lowered_at' => now()]);
    }
}
