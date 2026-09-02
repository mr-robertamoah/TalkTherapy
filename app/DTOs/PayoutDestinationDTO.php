<?php

namespace App\DTOs;

use App\Enums\PayoutDestinationTypeEnum;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class PayoutDestinationDTO extends BaseDTO
{
    public ?User $user = null;

    public ?PayoutDestinationTypeEnum $type = null;

    public ?string $accountNumber = null;

    // Paystack's bank/mobile-money-provider code -- not this platform's own currency code.
    public ?string $bankCode = null;

    public ?string $currency = null;
}
