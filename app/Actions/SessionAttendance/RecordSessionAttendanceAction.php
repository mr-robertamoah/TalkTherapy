<?php

namespace App\Actions\SessionAttendance;

use App\Actions\Action;
use App\DTOs\SessionAttendanceDTO;
use App\Enums\SessionAttendanceStatusEnum;
use App\Exceptions\SessionAttendanceException;
use App\Models\Session;
use App\Models\SessionAttendance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// SCRUM-445: saves the register a counsellor submitted, in one transaction under the session's row lock (so it can't
// interleave with closing the session as a no-show or a status change -- the status is checked again there). Each key
// is resolved server-side against the roster; one that isn't on it refuses the whole register. People left out of it
// keep what they had. A mark that doesn't change keeps its marked_at and who marked it. The marking counsellor is
// always the signed-in one. Never touches the session's status, payments or payouts.
// SCRUM-446: returns the people whose mark became ABSENT with this save (a first mark or a change -- not an unchanged
// one), so they can be told.
class RecordSessionAttendanceAction extends Action
{
    public const UNKNOWN_PERSON = 'Someone in this register is not part of this session.';

    /** @return Collection<int, User> */
    public function execute(SessionAttendanceDTO $dto): Collection
    {
        return DB::transaction(function () use ($dto) {
            $locked = Session::query()->whereKey($dto->session->id)->lockForUpdate()->first();

            if (! $locked || in_array($locked->status, EnsureCanRecordSessionAttendanceAction::FINAL_STATUSES, true)) {
                throw new SessionAttendanceException(EnsureCanRecordSessionAttendanceAction::WINDOW_CLOSED, 422);
            }

            $dto->session->unsetRelation('attendances');
            $roster = GetSessionAttendanceRosterAction::new()->execute($dto->session)->keyBy('key');

            $rows = [];
            $markedAbsent = collect();
            $now = now();

            foreach ($dto->entries as $entry) {
                $member = $roster->get((string) $entry['key']) ?? throw new SessionAttendanceException(self::UNKNOWN_PERSON, 422);

                if ($member['attendance']?->status !== $entry['status']) {
                    $rows[] = [
                        'session_id' => $dto->session->id,
                        'user_id' => $member['user']->id,
                        'status' => $entry['status'],
                        'marked_by_counsellor_id' => $dto->user->counsellor->id,
                        'marked_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($entry['status'] === SessionAttendanceStatusEnum::absent->value) {
                        $markedAbsent->push($member['user']);
                    }
                }
            }

            if ($rows) {
                SessionAttendance::query()->upsert($rows, ['session_id', 'user_id'], ['status', 'marked_by_counsellor_id', 'marked_at', 'updated_at']);
            }

            $dto->session->unsetRelation('attendances');

            return $markedAbsent;
        });
    }
}
