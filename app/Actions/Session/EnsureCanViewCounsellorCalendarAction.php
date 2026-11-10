<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\GetCounsellorCalendarSessionsDTO;
use App\Exceptions\MustBeCounsellorException;

class EnsureCanViewCounsellorCalendarAction extends Action
{
    // Deliberately no admin bypass, unlike EnsureIsCounsellorAction -- this is a self-scoped
    // aggregate of a specific counsellor's own sessions, never an admin-wide or shared calendar
    // (product-owner acceptance criteria, SCRUM-212).
    public function execute(GetCounsellorCalendarSessionsDTO $dto)
    {
        if ($dto->user?->counsellor) {
            return;
        }

        throw new MustBeCounsellorException('You have to be a counsellor to view a session calendar.', 422);
    }
}
