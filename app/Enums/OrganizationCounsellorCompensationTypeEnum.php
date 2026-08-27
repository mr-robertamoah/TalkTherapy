<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationCounsellorCompensationTypeEnum: string
{
    use EnumTrait;

    case fixed = 'FIXED';
    case percentage = 'PERCENTAGE';
    case free = 'FREE';
}
