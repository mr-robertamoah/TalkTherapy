<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum TherapyStatusEnum : string {
    use EnumTrait;

    case pending = 'PENDING';
    case in_session = 'IN_SESSION';
    case ended = 'ENDED';
}