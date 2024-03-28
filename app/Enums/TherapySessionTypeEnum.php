<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum TherapySessionTypeEnum : string {
    use EnumTrait;

    case once = 'ONCE';
    case periodic = 'PERIODIC';
}