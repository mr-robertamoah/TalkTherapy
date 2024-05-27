<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum LinkStateEnum : string {
    use EnumTrait;

    case active = 'ACTIVE';
    case inactive = 'IN_ACTIVE';
}