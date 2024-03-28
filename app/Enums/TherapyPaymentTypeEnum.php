<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum TherapyPaymentTypeEnum : string {
    use EnumTrait;

    case free = 'FREE';
    case paid = 'PAID';
}