<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;

// TT-3.2f-f/SCRUM-323: the shared authorization for a counsellor's manual grant AND revoke of
// speaking permission -- GroupTherapy-only, any active counsellor (peer model, no single host;
// same as RemoveParticipantFromVideoSessionAction), never targeting yourself. Deliberately takes
// the target as a bare id and never resolves it here -- see VideoSession::activeParticipantFor().
class EnsureCanModerateSpeakingPermissionAction extends Action
{
    public function execute(Session $session, User $actingUser, int $targetUserId): void
    {
        if (! $session->for instanceof GroupTherapy) {
            throw new VideoException('Speaking permission is only available for group therapy video calls.', 422);
        }

        if (! $session->for->isCounsellorUser($actingUser)) {
            throw new VideoException('Only a counsellor may change who can speak in this group video call.', 422);
        }

        if ($actingUser->id === $targetUserId) {
            throw new VideoException('You cannot change your own speaking permission.', 422);
        }
    }
}
