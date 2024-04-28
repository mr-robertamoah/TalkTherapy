<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum ContactTypeEnum : string {
    use EnumTrait;

    case suggestion = 'SUGGESTION';
    case help = 'HELP';
    case general = 'GENERAL';
    case sponsorship = 'SPONSORSHIP';
}