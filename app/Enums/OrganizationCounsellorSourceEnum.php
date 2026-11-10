<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationCounsellorSourceEnum: string
{
    use EnumTrait;

    case invited = 'INVITED';
    case applied = 'APPLIED';
}
