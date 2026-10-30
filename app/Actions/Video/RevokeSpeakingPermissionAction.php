<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Contracts\VideoProviderInterface;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Events\SpeakingPermissionChangedEvent;
use App\Exceptions\VideoException;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Models\VideoSessionSpeakingGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// TT-3.2f-f/SCRUM-323: the ONE revoke implementation (architect's explicit design requirement) --
// execute() is a counsellor's manual revoke; revokeGrant() is the shared core that TT-3.2f-h's
// idle sweep and TT-3.2f-i's removal cleanup call directly with their own revocation reason.
class RevokeSpeakingPermissionAction extends Action
{
    // Mirrors RemoveParticipantFromVideoSessionAction: a target that isn't in the call is a safe
    // no-op.
    //
    // Security-review finding: grants belong to one join row, but the provider capability belongs
    // to the PERSON -- after a reconnect (a new join row, the old one possibly still open, see
    // SCRUM-330) the live row can have no grant while a stale row still does, and on Chime a
    // re-created attendee may keep its old SendReceive capability. So a manual revoke closes every
    // open grant this user holds in the call, and always ends by asserting receive-only at the
    // provider for their live join -- "revoked" is never reported without that having happened.
    public function execute(Session $session, User $actingUser, int $targetUserId): void
    {
        EnsureCanModerateSpeakingPermissionAction::new()->execute($session, $actingUser, $targetUserId);

        $videoSession = $session->videoSessions()->whereNull('ended_at')->first();
        $liveParticipant = $videoSession?->activeParticipantFor($targetUserId);

        if (! $liveParticipant) {
            return;
        }

        // Stale rows first (closed locally only, see revokeGrant()), the live row's own grant last
        // -- that's the one call that actually reaches the provider.
        $openGrants = VideoSessionSpeakingGrant::query()
            ->whereNull('revoked_at')
            ->whereIn('video_session_participant_id', $videoSession->participants()
                ->where('participant_type', User::class)
                ->where('participant_id', $targetUserId)
                ->select('id'))
            ->get()
            ->sortBy(fn (VideoSessionSpeakingGrant $grant) => $grant->video_session_participant_id === $liveParticipant->id);

        foreach ($openGrants as $grant) {
            $this->revokeGrant($grant, SpeakingPermissionRevocationReasonEnum::manual, $actingUser);
        }

        if (! $openGrants->contains('video_session_participant_id', $liveParticipant->id)) {
            $this->revokedAtProvider($videoSession, $liveParticipant->participant, SpeakingPermissionRevocationReasonEnum::manual);
        }
    }

    // Provider failure handling depends on WHY the grant is being revoked -- an explicit decision,
    // not the best-effort default (see VideoProviderInterface::updateParticipantCapabilities()):
    // - manual: fail loudly (502) and leave the grant open. A counsellor told "revoked" while the
    //   member can still speak is the worst outcome; they can retry or remove the member instead.
    // - idle_auto_expiry: log and leave the grant open, so the next sweep retries. Closing it
    //   locally would strand a member still publishing at the provider with no open grant left
    //   for anyone (sweep or counsellor) to find and revoke again.
    // - participant_removed: log and close locally anyway -- the member is being ejected from the
    //   room regardless, so their publish capability stops mattering.
    //
    // Callers must not wrap this in their own outer transaction: the broadcast below relies on
    // this method's own transaction having committed (after_commit=false, see
    // JoinVideoSessionAction::currentOrNewVideoSessionAndJoin()).
    public function revokeGrant(VideoSessionSpeakingGrant $grant, SpeakingPermissionRevocationReasonEnum $reason, ?User $revokedBy = null): void
    {
        $videoSession = $grant->videoSessionParticipant->videoSession;
        $targetUser = $grant->videoSessionParticipant->participant;

        $wasRevoked = DB::transaction(function () use ($grant, $reason, $revokedBy, $videoSession, $targetUser) {
            // Same lock as grant/raise/lower take, so every speaking-state change for a participant
            // is serialized behind one row -- a revoke can't interleave with a grant, or with the
            // idle sweep racing a manual revoke.
            $participant = VideoSessionParticipant::query()->lockForUpdate()->find($grant->video_session_participant_id);
            $grant = VideoSessionSpeakingGrant::query()->find($grant->id);

            if (! $grant->isActive()) {
                return false;
            }

            // The provider identifies people by user id, not by join-cycle -- a grant left open on a
            // stale row (a leave or reconnect that never closed it, see SCRUM-330) must not mute the
            // same person's CURRENT join, which may hold a newer, valid grant. Only touch the
            // provider when this grant's own row is the user's live one; otherwise just close it.
            $isCurrentJoin = $participant->isActive()
                && $videoSession->activeParticipantFor($targetUser->id)?->is($participant);

            if ($isCurrentJoin && ! $this->revokedAtProvider($videoSession, $targetUser, $reason)) {
                return false;
            }

            $grant->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $revokedBy?->id,
                'revocation_reason' => $reason->value,
            ]);

            return true;
        });

        if ($wasRevoked) {
            SpeakingPermissionChangedEvent::dispatch($videoSession, $targetUser->id, false, false);
        }
    }

    // Returns whether the grant should be closed locally -- see revokeGrant()'s own comment.
    private function revokedAtProvider(VideoSession $videoSession, User $targetUser, SpeakingPermissionRevocationReasonEnum $reason): bool
    {
        try {
            app(VideoProviderInterface::class)->updateParticipantCapabilities($videoSession, $targetUser, false, false);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Provider-side speaking-permission revoke failed.', [
                'video_session_id' => $videoSession->id,
                'target_user_id' => $targetUser->id,
                'revocation_reason' => $reason->value,
                'exception' => $exception->getMessage(),
            ]);

            return match ($reason) {
                SpeakingPermissionRevocationReasonEnum::manual => throw new VideoException('Unable to revoke speaking permission right now. Please try again, or remove them from the call.', 502),
                SpeakingPermissionRevocationReasonEnum::idle_auto_expiry => false,
                SpeakingPermissionRevocationReasonEnum::participant_removed => true,
            };
        }
    }
}
