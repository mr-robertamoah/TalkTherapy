<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-340: what a RESOLVED report concluded. Recording an outcome takes no action by itself --
// suspending an account is SCRUM-359.
enum ReportOutcomeEnum: string
{
    use EnumTrait;

    case no_action = 'no_action';
    case warning = 'warning';
    case suspension_recommended = 'suspension_recommended';
    case account_removed = 'account_removed';
    case other = 'other';
}
