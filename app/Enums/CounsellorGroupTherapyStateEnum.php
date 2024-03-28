<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum CounsellorGroupTherapyStateEnum : string {
    use EnumTrait;

    case active = 'ACTIVE';
    case inactive = 'INACTIVE';
}