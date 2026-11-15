<?php

namespace App\Services;

use App\Actions\Admin\RecordAdminContentReadAction;
use App\Actions\Session\EnsureSessionExistsAction;
use App\Actions\SessionAttendance\CloseSessionAsNoShowAction;
use App\Actions\SessionAttendance\EnsureCanRecordSessionAttendanceAction;
use App\Actions\SessionAttendance\EnsureCanViewSessionAttendanceAction;
use App\Actions\SessionAttendance\GetSessionAttendanceRegisterAction;
use App\Actions\SessionAttendance\RecordSessionAttendanceAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\Actions\Therapy\EnsureTherapyCanProceedAction;
use App\DTOs\CreateSessionDTO;
use App\DTOs\SessionAttendanceDTO;
use App\Events\SessionUpdatedEvent;
use App\Models\AdminContentAccess;
use App\Models\Session;
use App\Notifications\SessionStatusChangedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * SCRUM-445 (SCRUM-115): attendance at in-person sessions. A counsellor of the therapy or group marks each client or
 * member present or absent, from the start until limits.attendance_window_hours after the end; a client or member sees
 * only their own mark; counsellors and uninvolved administrators (logged) see the register. Attendance never moves
 * money: the only status change it leads to is closing a never-started session as FAILED when everyone was absent.
 */
class SessionAttendanceService extends Service
{
    public function getAttendance(SessionAttendanceDTO $dto): array
    {
        $this->ensureSessionExists($dto);

        EnsureCanViewSessionAttendanceAction::new()->execute($dto);

        $session = $dto->session;
        $user = $dto->user;

        if (! EnsureCanViewSessionAttendanceAction::seesRegister($session, $user)) {
            return [
                'register' => null,
                'viewerAttendance' => $session->attendances()->where('user_id', $user->id)->first()?->toOwnView(),
            ];
        }

        // Once per administrator per session per ten minutes, as getSession() logs its reads.
        if (EnsureCanViewSessionAttendanceAction::readsAsAdministrator($session, $user)
            && Cache::add("admin-session-attendance-read:{$user->id}:{$session->id}", 1, 600)) {
            RecordAdminContentReadAction::new()->execute($user, AdminContentAccess::SESSION_ATTENDANCE, $session);
        }

        return [
            'register' => GetSessionAttendanceRegisterAction::new()->execute($session),
            'viewerAttendance' => null,
            'recordableFrom' => $session->start_time->toIso8601String(),
            'recordableUntil' => EnsureCanRecordSessionAttendanceAction::closesAt($session)->toIso8601String(),
        ];
    }

    public function recordAttendance(SessionAttendanceDTO $dto): Session
    {
        $this->ensureCanWrite($dto);

        $session = RecordSessionAttendanceAction::new()->execute($dto);

        broadcast(new SessionUpdatedEvent($session))->toOthers();

        return $session;
    }

    public function closeAsNoShow(SessionAttendanceDTO $dto): Session
    {
        $this->ensureCanWrite($dto);

        $session = CloseSessionAsNoShowAction::new()->execute($dto);

        broadcast(new SessionUpdatedEvent($session))->toOthers();

        Notification::send($session->for->getOtherUsers($dto->user), new SessionStatusChangedNotification($session));

        return $session;
    }

    // SCRUM-339: a missing session gets the same refusal as one the caller can't use.
    private function ensureSessionExists(SessionAttendanceDTO $dto): void
    {
        EnsureSessionExistsAction::new()->execute(CreateSessionDTO::new()->fromArray(['session' => $dto->session]));
    }

    private function ensureCanWrite(SessionAttendanceDTO $dto): void
    {
        $this->ensureSessionExists($dto);

        EnsureCanRecordSessionAttendanceAction::new()->execute($dto);

        // Counsellor work: not while their counsellor role is suspended (they can still read the register).
        EnsureCanActAsCounsellorAction::new()->execute($dto->user->counsellor);

        // As for topics: not while the therapy can't proceed (e.g. paused for a minor's missing guardian).
        EnsureTherapyCanProceedAction::new()->execute($dto->user, $dto->session->for, includeTarget: false);
    }
}
