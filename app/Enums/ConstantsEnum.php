<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum ConstantsEnum : string
{
    use EnumTrait;

    case nationalId = "National Identification Authority";
}