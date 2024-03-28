<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum SessionsEnum : string {
    use EnumTrait;

    case held = 'HELD';
    case failed = 'FAILED';
    case pending = 'PENDING';
}