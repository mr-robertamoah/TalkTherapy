<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum TherapyStatusEnum : string {
    use EnumTrait;

    case pending = 'PENDING';
    case failed = 'FAILED';
    case held = 'HELD';
}