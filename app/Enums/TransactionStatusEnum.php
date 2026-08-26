<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum TransactionStatusEnum: string
{
    use EnumTrait;

    case pending = 'PENDING';
    case success = 'SUCCESS';
    case failed = 'FAILED';
    case abandoned = 'ABANDONED';
}
