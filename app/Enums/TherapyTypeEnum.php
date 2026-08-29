<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum TherapyTypeEnum: string
{
    use EnumTrait;

    case individual = 'INDIVIDUAL';
    case group = 'GROUP';
}
