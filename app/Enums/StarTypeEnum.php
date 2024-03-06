<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum StarTypeEnum : string {
    use EnumTrait;

    case contribution = 'CONTRIBUTION';
    case participation = 'PARTICIPATION';
    case commitment = 'COMMITMENT';
}