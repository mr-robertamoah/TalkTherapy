<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-32: a safety flag is open (nobody has picked it up), acknowledged (an administrator is handling it), withdrawn (by the counsellor) or resolved (with an outcome, TT-4.3b).
enum SafetyFlagStatusEnum: string
{
    use EnumTrait;

    case open = 'OPEN';
    case acknowledged = 'ACKNOWLEDGED';
    case withdrawn = 'WITHDRAWN';
    case resolved = 'RESOLVED';

    // TT-4.3b: how the therapy's counsellors see it -- never who is handling it, nor the outcome.
    public function counsellorLabel(): string
    {
        return match ($this) {
            self::open => 'Sent -- waiting for an administrator',
            self::acknowledged => 'An administrator is handling it',
            self::withdrawn => 'Withdrawn',
            self::resolved => 'Resolved',
        };
    }
}
