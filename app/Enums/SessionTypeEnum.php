<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum SessionTypeEnum : string {
    use EnumTrait;

    case online = 'ONLINE';
    case in_person = 'IN_PERSON';
}