<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-7.11e/SCRUM-475: what happened to a commission-free period (platform_fee_waiver_events.event).
enum PlatformFeeWaiverEventEnum: string
{
    use EnumTrait;

    case created = 'created';

    // "End now" on a running period.
    case ended = 'ended';

    // An upcoming period removed; the event keeps its dates and reason.
    case deleted = 'deleted';
}
