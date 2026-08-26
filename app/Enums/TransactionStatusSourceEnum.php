<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum TransactionStatusSourceEnum: string
{
    use EnumTrait;

    case initiate = 'INITIATE';
    case webhook = 'WEBHOOK';
    case verify = 'VERIFY';
}
