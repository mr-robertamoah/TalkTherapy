<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Contracts\VideoProviderInterface;
use App\Events\HandLoweredEvent;
use App\Events\SpeakingPermissionChangedEvent;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// TT-3.2f-f/SCRUM-323: any active counsellor lets a receive-only GroupTherapy member publish,
// whether or not they raised a hand first (a counsellor may invite someone to speak). Granting
// acts on any open hand-raise, closing it.
//
// Provider failure is deliberately NOT best-effort here, unlike endRoom()/removeParticipant():
// a silent failure would leave the counsellor believing the member can now speak while the
// provider still blocks them (SCRUM-315's own finding about silent failures). The provider call
// runs inside the transaction, so a failure rolls the grant row back and surfaces a 502 the
// counsellor can retry -- local state never claims a grant the provider didn't apply.
class GrantSpeakingPermissionAction extends Action
{
    public function execute(Session $session, User $actingUser, int $targetUserId): VideoSessionSpeakingGrant
    {
        EnsureCanModerateSpeakingPermissionAction::new()->execute($session, $actingUser, $targetUserId);

        $videoSession = $session->videoSessions()->whereNull('ended_at')->first();

        if (! $videoSession) {
            throw new VideoException('There is no active video call for this session.', 422);
        }

        // Same message whether the id doesn't exist at all or simply isn't in this call -- see
        // VideoSession::activeParticipantFor() for why that must stay indistinguishable.
        $participant = $videoSession->activeParticipantFor($targetUserId)
            ?? throw new VideoException('That person is not currently in this video call.', 422);

        $targetUser = $participant->participant;

        // Security-review finding: an open participant row can outlive group membership (left or
        // removed from the group mid-call, or a stale row -- SCRUM-330). Same message as "not in
        // the call", so this reveals nothing new about the target.
        if (! $session->for->isParticipant($targetUser)) {
            throw new VideoException('That person is not currently in this video call.', 422);
        }

        if (! $session->for->isReceiveOnlyVideoParticipant($targetUser)) {
            throw new VideoException('This participant can already speak in this video call.', 422);
        }

        EnsureMemberMayBeGrantedSpeakingAction::new()->execute($session->for, $targetUser);

        [$canSendAudio, $canSendVideo] = $this->capabilitiesFor($session->for, $targetUser);

        // Events are dispatched only after the transaction commits -- see
        // JoinVideoSessionAction::currentOrNewVideoSessionAndJoin() for why (after_commit=false).
        $wasNewlyGranted = false;
        $loweredHand = false;

        $grant = DB::transaction(function () use ($participant, $actingUser, $videoSession, $targetUser, $canSendAudio, $canSendVideo, &$wasNewlyGranted, &$loweredHand) {
            // Serializes two counsellors granting the same member at the same instant, so only
            // one open grant row can ever exist per join-cycle.
            $participant = VideoSessionParticipant::query()->lockForUpdate()->find($participant->id);

            if (! $participant->isActive()) {
                throw new VideoException('That person is not currently in this video call.', 422);
            }

            if ($existing = $participant->currentSpeakingGrant()) {
                return $existing;
            }

            $grant = $participant->speakingGrants()->create([
                'granted_by_user_id' => $actingUser->id,
                'granted_at' => now(),
                // Seeded at grant time so TT-3.2f-h's idle sweep measures from the grant itself
                // until the first activity ping arrives.
                'last_activity_at' => now(),
            ]);

            $loweredHand = (bool) $participant->handRaises()->whereNull('lowered_at')->update(['lowered_at' => now()]);

            try {
                app(VideoProviderInterface::class)->updateParticipantCapabilities($videoSession, $targetUser, $canSendAudio, $canSendVideo);
            } catch (Throwable $exception) {
                Log::warning('Provider-side speaking-permission grant failed; grant rolled back.', [
                    'video_session_id' => $videoSession->id,
                    'target_user_id' => $targetUser->id,
                    'exception' => $exception->getMessage(),
                ]);

                throw new VideoException('Unable to grant speaking permission right now. Please try again shortly.', 502);
            }

            $wasNewlyGranted = true;

            return $grant;
        });

        if ($loweredHand) {
            HandLoweredEvent::dispatch($videoSession, $targetUser->id);
        }

        if ($wasNewlyGranted) {
            SpeakingPermissionChangedEvent::dispatch($videoSession, $targetUser->id, $canSendAudio, $canSendVideo);
        }

        return $grant;
    }

    // TT-3.2f-g/SCRUM-324: an anonymous member (group-wide flag OR their own pivot flag -- the same
    // rule isAnonymousFor() applies when masking their name) gets audio-only, forever: their camera
    // capability is never enabled at the PROVIDER, so no client can turn it on. Everyone else gets
    // audio + video; the frontend (TT-3.2f-j) shows a one-time "speaking reveals your voice/face"
    // warning, keyed off the canSendVideo flag SpeakingPermissionChangedEvent carries.
    //
    // Security-review hardening: read straight from the membership row rather than via
    // isAnonymousFor(), which reads a possibly-cached `users` relation and treats a non-member as
    // NOT anonymous. Here, video is only ever enabled for a positively-confirmed, non-anonymous
    // member of a non-anonymous group -- anything else fails closed to audio-only.
    private function capabilitiesFor(GroupTherapy $groupTherapy, User $targetUser): array
    {
        $membership = $groupTherapy->users()->where('users.id', $targetUser->id)->first();

        return [true, ! $groupTherapy->anonymous && $membership && ! $membership->pivot->anonymous];
    }
}
