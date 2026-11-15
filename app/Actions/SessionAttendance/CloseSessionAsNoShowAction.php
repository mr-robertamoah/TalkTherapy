<?php

namespace App\Actions\SessionAttendance;

use App\Actions\Action;
use App\Actions\Session\ChangeSessionStatusAction;
use App\DTOs\CreateSessionDTO;
use App\DTOs\SessionAttendanceDTO;
use App\Enums\SessionAttendanceStatusEnum;
use App\Enums\SessionStatusEnum;
use App\Exceptions\SessionAttendanceException;
use App\Models\Counsellor;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-445: when everyone the session is for was marked ABSENT, its counsellor closes it as FAILED (through
// ChangeSessionStatusAction, so ended_at is set) -- only once its end time has passed, and only while nobody on the
// client side ever took part in starting it: PENDING, or a start asked for by the counsellors' side and not yet
// confirmed. Never when a client or member asked to start, and never a session that started, was held or billed
// (FAILED records no organisation invoice line: only HELD does). Under the session's row lock, as attendance writes and
// the start/end/fail status changes (SessionService) take it.
class CloseSessionAsNoShowAction extends Action
{
    public const NOT_ENDED = 'A session can be closed as a no-show once its end time has passed.';

    public const NOT_CLOSABLE = 'Only a session that never started can be closed as a no-show.';

    public const NOT_ALL_ABSENT = 'A session can be closed as a no-show only when everyone is marked absent.';

    public function execute(SessionAttendanceDTO $dto): Session
    {
        if (! now()->greaterThan($dto->session->end_time)) {
            throw new SessionAttendanceException(self::NOT_ENDED, 422);
        }

        return DB::transaction(function () use ($dto) {
            $locked = Session::query()->whereKey($dto->session->id)->lockForUpdate()->first();

            if (! $locked || ! $this->neverStarted($locked, $dto->session)) {
                throw new SessionAttendanceException(self::NOT_CLOSABLE, 422);
            }

            $dto->session->unsetRelation('attendances');
            $roster = GetSessionAttendanceRosterAction::new()->execute($dto->session);

            if ($roster->isEmpty() || $roster->contains(fn (array $member) => $member['attendance']?->status !== SessionAttendanceStatusEnum::absent->value)) {
                throw new SessionAttendanceException(self::NOT_ALL_ABSENT, 422);
            }

            $dto->session->setRawAttributes($locked->getAttributes(), true);
            $dto->session->unsetRelation('updatedby');

            return ChangeSessionStatusAction::new()->execute(
                CreateSessionDTO::new()->fromArray(['user' => $dto->user, 'session' => $dto->session, 'for' => $dto->session->for]),
                SessionStatusEnum::failed->value,
            );
        });
    }

    private function neverStarted(Session $locked, Session $session): bool
    {
        if ($locked->ended_at) {
            return false;
        }

        if ($locked->status === SessionStatusEnum::pending->value) {
            return true;
        }

        if ($locked->status !== SessionStatusEnum::in_session_confirmation->value) {
            return false;
        }

        // Who asked to start it: only the counsellors' side (an old request with no asker recorded counts as not).
        $asker = $locked->updatedby;
        $counsellor = $asker instanceof Counsellor ? $asker : ($asker instanceof User ? $asker->counsellor : null);

        return $counsellor !== null && (bool) $session->for?->isCounsellor($counsellor);
    }
}
