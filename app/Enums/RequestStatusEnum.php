<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum RequestStatusEnum : string {
    use EnumTrait;

    case pending = 'PENDING';
    case rejected = 'REJECTED';
    case accepted = 'ACCEPTED';
}