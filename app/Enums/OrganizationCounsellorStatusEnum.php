<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationCounsellorStatusEnum: string
{
    use EnumTrait;

    // Accepted, but not yet active -- TT-6.4b (SCRUM-122) transitions this to `active` once
    // compensation terms are agreed. Affiliation exists and is queryable, but doesn't yet
    // carry any payment/compensation implications.
    case pending = 'PENDING';
    case active = 'ACTIVE';
    case ended = 'ENDED';
}
