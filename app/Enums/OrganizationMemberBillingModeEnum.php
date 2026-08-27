<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationMemberBillingModeEnum: string
{
    use EnumTrait;

    case retainer = 'RETAINER';
    case payPerUse = 'PAY_PER_USE';
}
