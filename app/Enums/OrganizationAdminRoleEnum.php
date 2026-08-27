<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationAdminRoleEnum: string
{
    use EnumTrait;

    case owner = 'OWNER';
    case admin = 'ADMIN';
}
