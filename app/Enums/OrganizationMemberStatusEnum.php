<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationMemberStatusEnum: string
{
    use EnumTrait;

    case active = 'ACTIVE';
    case ended = 'ENDED';
}
