<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Exceptions\VideoException;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-3.1a/SCRUM-274: the one gate every video entry point (join, and later leave/end) must pass
// through -- deliberately separate from payment gating (TT-3.1b's own job) and from guardian/minor
// consent (TT-3.1e's own job, not yet built) so each concern stays independently testable.
class EnsureVideoIsAvailableForSessionAction extends Action
{
    public function execute(Session $session, User $user): void
    {
        // TT-3.1 is scoped to 1:1 (individual Therapy) sessions only -- GroupTherapy video is
        // TT-3.2, not yet scoped. Deliberately explicit here rather than silently allowing a
        // GroupTherapy session through and producing a confusing 2-participant-only room.
        if (! $session->for instanceof Therapy) {
            throw new VideoException('Video is not yet available for group therapy sessions.', 422);
        }

        if ($session->type !== SessionTypeEnum::online->value) {
            throw new VideoException('Video is only available for online sessions.', 422);
        }

        if (! in_array($session->status, [SessionStatusEnum::in_session->value, SessionStatusEnum::in_session_confirmation->value])) {
            throw new VideoException('Video is only available while the session is in progress.', 422);
        }

        if ($session->isNotParticipant($user)) {
            throw new VideoException('You are not allowed to join this session.', 422);
        }
    }
}
