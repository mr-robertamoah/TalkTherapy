<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// SCRUM-32: whether the counsellor discussed the concern with the client.
enum SafetyFlagDiscussedEnum: string
{
    use EnumTrait;

    case yes = 'YES';
    case no = 'NO';
    case notPossible = 'NOT_POSSIBLE';
}
