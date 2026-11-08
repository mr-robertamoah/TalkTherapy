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
}
