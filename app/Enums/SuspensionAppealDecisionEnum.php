<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-359: how an appeal against a suspension was decided.
enum SuspensionAppealDecisionEnum: string
{
    use EnumTrait;

    case upheld = 'upheld';
    case lifted = 'lifted';
}
