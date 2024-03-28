<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum TherapyPerPaymentEnum : string {
    use EnumTrait;

    case therapy = 'PER_THERAPY';
    case session = 'PER_SESSION';
}