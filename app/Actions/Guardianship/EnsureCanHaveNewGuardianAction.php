<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Exceptions\UserException;
use App\Models\User;

// SCRUM-441 (the user's decision, 2026-11-15): guardians are for under-18s only. Someone 18 or over can't ask for a
// guardian -- by request or link -- and a request or link can't be taken up once its ward has turned 18. Existing
// guardianships are untouched (an adult's guardian can still end theirs).
class EnsureCanHaveNewGuardianAction extends Action
{
    public const ADULT = 'Only someone under 18 can have a guardian added.';

    // For someone other than the ward (the person asked to be guardian): nothing about the ward's age.
    public const NO_LONGER = 'This request can no longer be accepted.';

    public function execute(User $ward, string $message = self::ADULT): void
    {
        if ($ward->isAdult()) {
            throw new UserException($message, 422);
        }
    }
}
