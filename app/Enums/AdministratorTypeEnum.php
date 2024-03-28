<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum AdministratorTypeEnum : string {
    use EnumTrait;

    case super = 'SUPER';
    case normal = 'NORMAL';
}