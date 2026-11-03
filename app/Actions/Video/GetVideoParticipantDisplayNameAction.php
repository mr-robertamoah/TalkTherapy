<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Enums\ConstantsEnum;
use App\Exceptions\AppException;
use App\Exceptions\VideoException;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Support\VideoDisplayName;
use App\Support\VideoParticipantKey;

// SCRUM-334: the label the call UI shows for another participant's tile. Daily lets anyone rename
// themselves from their own browser (setUserName(), no token/room property prevents it), so a masked
// "Anonymous" participant could relabel their tile with a counsellor's name. The UI therefore never
// shows the provider-side name: it asks here, by the participant key -- the provider identity WE
// minted into their token (Daily `user_id`, Chime `ExternalUserId`), which their browser can't change.
class GetVideoParticipantDisplayNameAction extends Action
{
    // One response for every refusal -- a viewer who isn't in the call, a key that matches no one,
    // and a real person who isn't in this call all look identical (see VideoParticipantKey::resolve
    // and SCRUM-357), so this can't be used to probe who exists or who has joined.
    private const NOT_IN_CALL = 'That participant is not in this video call.';

    public function execute(Session $session, User $viewer, string $participantKey): array
    {
        $videoSession = $session->videoSessions()->whereNull('ended_at')->first();

        if (! $videoSession || ! $this->viewerIsInCall($session, $videoSession, $viewer)) {
            throw new VideoException(self::NOT_IN_CALL, 404);
        }

        $targetId = VideoParticipantKey::resolve($session, $participantKey);

        // Only someone who has joined THIS call (any join row, open or not -- a reconnect can briefly
        // show a tile whose row was already closed), not an earlier one of the same session.
        $target = $targetId && $videoSession->participantRowsFor($targetId)->exists()
            ? User::find($targetId)
            : null;

        if (! $target) {
            throw new VideoException(self::NOT_IN_CALL, 404);
        }

        return [
            'key' => $participantKey,
            'displayName' => $this->belongsToTherapy($session, $target)
                ? VideoDisplayName::for($session, $target)
                : ConstantsEnum::anonymousUserLabel->value,
        ];
    }

    // Security review: names follow the therapy as it is now, and a member's own anonymity lives on
    // their membership row. Someone no longer a member (once members can leave or be removed) would
    // read as "not anonymous" and show their real name, so anyone who isn't the group's counsellor,
    // creator or a member is labelled Anonymous. A 1:1 therapy's two people never stop belonging.
    private function belongsToTherapy(Session $session, User $target): bool
    {
        $therapy = $session->for;

        if (! $therapy instanceof GroupTherapy) {
            return true;
        }

        return $therapy->isCounsellorUser($target)
            || $therapy->isUser($target)
            || $therapy->users()->whereKey($target->id)->exists();
    }

    // Currently in the call (an open join row) AND still allowed into it -- someone removed from the
    // therapy, or a session that's no longer in progress, must not keep resolving names off a stale
    // row. The availability check's own reasons are never surfaced: they'd make an outsider's
    // response differ from an unknown key's.
    private function viewerIsInCall(Session $session, VideoSession $videoSession, User $viewer): bool
    {
        if (! $videoSession->hasActiveParticipant($viewer)) {
            return false;
        }

        try {
            EnsureVideoIsAvailableForSessionAction::new()->execute($session, $viewer);
        } catch (AppException) {
            return false;
        }

        return true;
    }
}
