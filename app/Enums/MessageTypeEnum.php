<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum MessageTypeEnum : string {
    use EnumTrait;

    case normal = 'NORMAL';
    case statement = 'STATEMENT';
    case question = 'QUESTION';
    case answer = 'ANSWER';
}