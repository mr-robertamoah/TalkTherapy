<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum MessageStatusEnum : string {
    use EnumTrait;

    case sent = 'SENT';
    case seen = 'SEEN';
    case received = 'RECEIVED';
}