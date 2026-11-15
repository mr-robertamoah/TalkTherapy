<?php

namespace App\Actions\SessionAttendance;

use App\Actions\Action;
use App\Models\Session;

// SCRUM-445: the register as a session's counsellors and administrators see it -- each person by key and label (never
// a user id), with their mark, or null while unmarked. Never who marked it. A read only: callers check
// EnsureCanViewSessionAttendanceAction::seesRegister() first.
class GetSessionAttendanceRegisterAction extends Action
{
    public function execute(Session $session): array
    {
        return GetSessionAttendanceRosterAction::new()->execute($session)
            ->map(fn (array $member) => [
                'key' => $member['key'],
                'name' => $member['name'],
                'status' => $member['attendance']?->status,
                'markedAt' => $member['attendance']?->marked_at?->toIso8601String(),
            ])
            ->all();
    }
}
