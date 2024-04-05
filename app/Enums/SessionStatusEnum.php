<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum SessionStatusEnum : string {
    use EnumTrait;

    case pending = 'PENDING'; // scheduled
    case in_session_confirmation = 'IN_SESSION_CONFIRMATION'; // when in person and waiting to end
    case in_session = 'IN_SESSION'; // when having the session
    case failed = 'FAILED'; // when scheduled but not held
    case abandoned = 'ABANDONED'; // held but ended before end time
    case held = 'HELD';
    case held_confirmation = 'HELD_CONFIRMATION';
}