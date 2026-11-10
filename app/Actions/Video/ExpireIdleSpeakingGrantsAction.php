<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Enums\SpeakingPermissionRevocationReasonEnum;
use App\Models\VideoSessionSpeakingGrant;
use Illuminate\Support\Facades\Log;
use Throwable;

// TT-3.2f-h/SCRUM-325: reverts any open speaking grant whose member's client hasn't reported
// activity within config('video.speaking_grant_idle_timeout') -- through the SAME shared
// RevokeSpeakingPermissionAction a counsellor's manual revoke uses (reason idle_auto_expiry).
// Only grants in a still-open video call: an ended call has no live attendee to mute. A provider
// failure leaves the grant open for the next run to retry (see revokeGrant()); any other failure
// on one grant is logged and the sweep moves on to the rest.
// SCRUM-376: moved out of AppService.
class ExpireIdleSpeakingGrantsAction extends Action
{
    public function execute(): void
    {
        $cutoff = now()->subSeconds(config('video.speaking_grant_idle_timeout'));
        $revoke = RevokeSpeakingPermissionAction::new();

        VideoSessionSpeakingGrant::query()
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query
                ->where('last_activity_at', '<=', $cutoff)
                ->orWhere(fn ($query) => $query->whereNull('last_activity_at')->where('granted_at', '<=', $cutoff)))
            ->whereHas('videoSessionParticipant.videoSession', fn ($query) => $query->whereNull('ended_at'))
            ->with(['videoSessionParticipant.videoSession', 'videoSessionParticipant.participant'])
            ->orderBy('id')
            ->get()
            ->each(function (VideoSessionSpeakingGrant $grant) use ($revoke, $cutoff) {
                try {
                    $revoke->revokeGrant($grant, SpeakingPermissionRevocationReasonEnum::idle_auto_expiry, idleCutoff: $cutoff);
                } catch (Throwable $exception) {
                    Log::error('Failed to expire an idle speaking grant.', [
                        'grantId' => $grant->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
