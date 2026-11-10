<?php

namespace App\DTOs;

use App\Models\Organization;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class OrganizationPaymentInstrumentDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Organization $organization = null;

    public ?string $currency = null;

    public ?string $callbackUrl = null;
}
