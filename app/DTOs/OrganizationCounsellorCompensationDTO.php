<?php

namespace App\DTOs;

use App\Models\OrganizationCounsellor;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class OrganizationCounsellorCompensationDTO extends BaseDTO
{
    public ?User $user = null;

    public ?OrganizationCounsellor $organizationCounsellor = null;

    public ?string $type = null;

    public ?int $amount = null;

    public ?string $currency = null;

    public ?int $percentage = null;

    public ?string $basis = null;
}
