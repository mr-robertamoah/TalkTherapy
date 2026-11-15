<?php

namespace App\Actions\SessionAttendance;

use App\Actions\Action;
use App\DTOs\SessionAttendanceDTO;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Exceptions\SessionAttendanceException;
use App\Models\Session;
use App\Models\User;
use Carbon\CarbonInterface;

// SCRUM-445: only a counsellor of the therapy or group (a 1:1's counsellor; a group's active counsellors and its
// counsellor creator) marks attendance -- never an administrator as one -- and only for an in-person session, from its
// start until limits.attendance_window_hours after its end, and not once the session was closed as FAILED or ABANDONED
// (a closed session's register is final; a HELD one can still be corrected). Suspension and the therapy's own state are checked by the
// service (EnsureCanActAsCounsellorAction, EnsureTherapyCanProceedAction), as for topics.
class EnsureCanRecordSessionAttendanceAction extends Action
{
    public const NOT_ALLOWED = 'You are not allowed to do that with this session.';

    public const ONLY_IN_PERSON = 'Attendance is only kept for in-person sessions.';

    public const NOT_STARTED = 'Attendance can be marked once the session has started.';

    public const WINDOW_CLOSED = 'Attendance can no longer be changed for this session.';

    public const FINAL_STATUSES = [SessionStatusEnum::failed->value, SessionStatusEnum::abandoned->value];

    public function execute(SessionAttendanceDTO $dto): void
    {
        if (! self::isCounsellorOf($dto->session, $dto->user)) {
            throw new SessionAttendanceException(self::NOT_ALLOWED, 422);
        }

        self::ensureInPerson($dto->session);

        if (in_array($dto->session->status, self::FINAL_STATUSES, true)) {
            throw new SessionAttendanceException(self::WINDOW_CLOSED, 422);
        }

        if (now()->lessThan($dto->session->start_time)) {
            throw new SessionAttendanceException(self::NOT_STARTED, 422);
        }

        if (now()->greaterThan(self::closesAt($dto->session))) {
            throw new SessionAttendanceException(self::WINDOW_CLOSED, 422);
        }
    }

    public static function isCounsellorOf(Session $session, ?User $user): bool
    {
        return (bool) ($user?->counsellor && $session->for?->isCounsellor($user->counsellor));
    }

    public static function ensureInPerson(Session $session): void
    {
        if ($session->type !== SessionTypeEnum::in_person->value) {
            throw new SessionAttendanceException(self::ONLY_IN_PERSON, 422);
        }
    }

    public static function closesAt(Session $session): CarbonInterface
    {
        return $session->end_time->copy()->addHours(config('limits.attendance_window_hours'));
    }
}
