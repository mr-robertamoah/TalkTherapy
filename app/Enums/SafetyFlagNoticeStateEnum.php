<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-32: where the client's notice, or a minor's guardians' notice, stands (TT-4.3c sends them).
// Pending: held for the handling administrator. Held: they're keeping it back for a recorded safety
// reason. Sent, or withheld for good with a reason. Not applicable: guardians of an adult, or a
// non-urgent flag.
enum SafetyFlagNoticeStateEnum: string
{
    use EnumTrait;

    case pending = 'PENDING';
    case held = 'HELD';
    case sent = 'SENT';
    case withheld = 'WITHHELD';
    case notApplicable = 'NOT_APPLICABLE';
}
