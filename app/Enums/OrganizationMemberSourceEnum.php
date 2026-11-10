<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationMemberSourceEnum: string
{
    use EnumTrait;

    case invited = 'INVITED';
    case applied = 'APPLIED';
}
