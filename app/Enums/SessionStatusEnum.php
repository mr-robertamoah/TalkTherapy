<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum SessionStatusEnum: string
{
    use EnumTrait;

    case pending = 'PENDING'; // scheduled
    case in_session_confirmation = 'IN_SESSION_CONFIRMATION'; // when in person and waiting to end
    case in_session = 'IN_SESSION'; // when having the session
    case failed = 'FAILED'; // when scheduled but not held
    case abandoned = 'ABANDONED'; // held but ended before end time
    case held = 'HELD';
    case held_confirmation = 'HELD_CONFIRMATION';

    // SCRUM-369: a session in one of these is over, even inside its time window. Not
    // HELD_CONFIRMATION: the other party still has to confirm it (see
    // Session::scopeWhereIsNotUserWhoConfirmedHeld).
    public static function finished(): array
    {
        return [self::failed->value, self::abandoned->value, self::held->value];
    }
}
