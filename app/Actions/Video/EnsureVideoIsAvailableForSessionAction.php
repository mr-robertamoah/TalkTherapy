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
// through -- deliberately separate from payment gating (TT-3.1b's own job) so each concern stays
// independently testable.
//
// TT-3.1e/SCRUM-278 interim safeguard (2026-09-11): SCRUM-278's own ticket text requires that
// "TT-3.1a-d must not ship minor video access by default -- until [a real guardian consent] flow
// exists, minor clients should be blocked from the Join video control entirely (fail closed)".
// That flow needs its own dedicated /start-feature scoping pass (a policy/safeguarding decision,
// not a pure engineering one). This is NOT that flow -- it is the interim fail-closed block the
// ticket itself calls for, applied urgently because TT-3.1a-d had already merged with NO such
// check at all: a minor with a guardian on file can already own a Therapy as its addedby
// (EnsureCanCreateTherapyAction allows `$user->isAdult() || $user->hasGuardian()`), and nothing in
// this file checked age before this fix -- a live, reachable gap, not a theoretical one. Treated
// as a bugfix (CLAUDE.md's "how much process a task needs": closing an already-identified,
// already-decided safeguarding gap in shipped code, not a new feature), logged in
// documentation/decision-log.md. This block must be removed (or superseded by an explicit
// consent check) once SCRUM-278's real flow ships.
//
// Scoped to $user themselves (the actual joiner), not "the therapy's client, regardless of who's
// joining" -- the ticket's own wording is "minor CLIENTS", and the counsellor side is never gated
// by ANY check in this codebase's whole payment/authorization epic (see
// EnsureUserCanAccessTherapyContentAction's identical "never gates the counsellor" precedent) --
// there's no reason for this interim block to be the first exception to that rule.
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

        // TT-3.1e/SCRUM-278 interim safeguard -- see class docblock. The counsellor side is
        // exempt (mirrors JoinVideoSessionAction's own identical $isOwner computation) -- this
        // blocks the minor CLIENT's own join attempt, not the counsellor's.
        $isCounsellor = (bool) ($user->counsellor && $session->for->isCounsellor($user->counsellor));

        if (! $isCounsellor && ! $user->isAdult()) {
            throw new VideoException('Video is not yet available for accounts under 18. Guardian consent support is coming soon.', 422);
        }

        if ($session->type !== SessionTypeEnum::online->value) {
            throw new VideoException('Video is only available for online sessions.', 422);
        }

        if (! in_array($session->status, [SessionStatusEnum::in_session->value, SessionStatusEnum::in_session_confirmation->value])) {
            throw new VideoException('Video is only available while the session is in progress.', 422);
        }
    }
}
