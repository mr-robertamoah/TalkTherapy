<?php

namespace App\Actions\SessionAttendance;

use App\Actions\Action;
use App\Actions\Message\EnsureUserCanAccessTherapyContentAction;
use App\DTOs\SessionAttendanceDTO;
use App\Exceptions\SessionAttendanceException;
use App\Models\Session;
use App\Models\User;

// SCRUM-445: reading an in-person session's attendance. Whoever may read the session's content
// (EnsureUserCanAccessTherapyContentAction, as getSession) gets a view; the register only goes to a counsellor of the
// therapy or group, or an administrator not involved in it (TherapyTrait::involvesInOversight, as reports and safety
// flags). Everyone else -- members, an involved administrator -- sees only their own mark.
class EnsureCanViewSessionAttendanceAction extends Action
{
    public function execute(SessionAttendanceDTO $dto): void
    {
        $session = $dto->session;

        if (! $session->for || ! EnsureUserCanAccessTherapyContentAction::new()->execute($session->for, $dto->user, $session, $session->start_time)) {
            throw new SessionAttendanceException(EnsureCanRecordSessionAttendanceAction::NOT_ALLOWED, 422);
        }

        EnsureCanRecordSessionAttendanceAction::ensureInPerson($session);
    }

    public static function seesRegister(Session $session, ?User $viewer): bool
    {
        return $viewer !== null
            && (EnsureCanRecordSessionAttendanceAction::isCounsellorOf($session, $viewer) || self::readsAsAdministrator($session, $viewer));
    }

    // Seen only because they are an administrator: such reads are logged (RecordAdminContentReadAction, with no context
    // -- the register is never public, even when the therapy is).
    public static function readsAsAdministrator(Session $session, User $viewer): bool
    {
        return $viewer->isAdmin() && ! $session->for->involvesInOversight($viewer);
    }
}
