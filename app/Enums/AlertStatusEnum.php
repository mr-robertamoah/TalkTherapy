<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum AlertStatusEnum : string {
    use EnumTrait;

    case waiting = 'WAITING';
    case alert = 'ALERT';
    case alert_not = 'ALERT_NOT';
}