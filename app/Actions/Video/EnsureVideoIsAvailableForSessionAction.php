<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Actions\VideoConsent\HasValidVideoConsentForSessionAction;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Exceptions\VideoConsentRequiredException;
use App\Exceptions\VideoException;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-3.1a/SCRUM-274: the one gate every video entry point (join, and later leave/end) must pass
// through -- deliberately separate from payment gating (TT-3.1b's own job) so each concern stays
// independently testable.
//
// TT-3.1e-d/SCRUM-283: a minor client (the therapy's own addedby, joining as themselves) needs a
// real, currently-valid guardian video-consent grant -- HasValidVideoConsentForSessionAction's own
// single OR-across-both-scopes query, per the architect's design (TT-3.1e-a/b/c). This replaced
// the interim fail-closed block SCRUM-278's own ticket text originally called for while the real
// consent flow was being built (see documentation/decision-log.md's SCRUM-278 entries for that
// interim fix's own reasoning and its later removal).
//
// Scoped to $user themselves (the actual joiner), not "the therapy's client, regardless of who's
// joining" -- the counsellor side is never gated by ANY check in this codebase's whole payment/
// authorization epic (see EnsureUserCanAccessTherapyContentAction's identical "never gates the
// counsellor" precedent) -- there's no reason for this check to be the first exception to that
// rule.
class EnsureVideoIsAvailableForSessionAction extends Action
{
    public function execute(Session $session, User $user): void
    {
        // TT-3.1b/SCRUM-275 security-review finding: this check now runs FIRST, not last. This
        // action became HTTP-reachable via VideoSessionController in TT-3.1b, which resolves any
        // Session by id from the URL with no ownership scoping -- with the participant check
        // last, the other three checks' distinct messages (group-vs-1:1, online-vs-in-person,
        // in-progress-vs-not) let an unrelated authenticated user probe an arbitrary session id
        // and learn its therapy type/delivery mode/live status before ever failing authorization.
        // Checking participation first means a non-participant always gets the exact same
        // generic denial, regardless of what the session actually is.
        if ($session->isNotParticipant($user)) {
            throw new VideoException('You are not allowed to join this session.', 422);
        }

        // TT-3.1 is scoped to 1:1 (individual Therapy) sessions only -- GroupTherapy video is
        // TT-3.2, not yet scoped. Deliberately explicit here rather than silently allowing a
        // GroupTherapy session through and producing a confusing 2-participant-only room.
        if (! $session->for instanceof Therapy) {
            throw new VideoException('Video is not yet available for group therapy sessions.', 422);
        }

        // TT-3.1e-d/SCRUM-283: the counsellor side is exempt (mirrors JoinVideoSessionAction's own
        // identical $isOwner computation) -- this gates the minor CLIENT's own join attempt, not
        // the counsellor's. An adult joiner never needs a consent check at all.
        //
        // Security-review note (2026-09-11): this check runs once, synchronously, at join time --
        // if a guardian revokes consent in the exact instant between this read and credential
        // issuance below (JoinVideoSessionAction), the join can still succeed once. Accepted,
        // pre-existing risk shape (the payment gate earlier in the same call chain has an
        // identical single-read-then-act window) -- NOT the same as revoking DURING an already-
        // active call, which InvalidateVideoConsentAction (TT-3.1e-c) tears down synchronously and
        // unconditionally, regardless of when the call started.
        $isCounsellor = (bool) ($user->counsellor && $session->for->isCounsellor($user->counsellor));

        if (! $isCounsellor && ! $user->isAdult() && ! HasValidVideoConsentForSessionAction::new()->execute($session)) {
            // TT-3.1e-f/SCRUM-285: a dedicated exception type, not plain VideoException -- lets
            // VideoSessionController surface a specific videoConsentRequired flag to the frontend
            // rather than the client having to string-match this message.
            throw new VideoConsentRequiredException('Guardian video consent is required before this account can join video for this session.', 422);
        }

        if ($session->type !== SessionTypeEnum::online->value) {
            throw new VideoException('Video is only available for online sessions.', 422);
        }

        if (! in_array($session->status, [SessionStatusEnum::in_session->value, SessionStatusEnum::in_session_confirmation->value])) {
            throw new VideoException('Video is only available while the session is in progress.', 422);
        }
    }
}
