<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum LicensingTypeEnum : string {
    use EnumTrait;

    case number = 'NUMBER';
    case file = 'FILE';
    case both = 'BOTH';
}