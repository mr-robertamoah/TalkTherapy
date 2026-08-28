<?php

namespace App\DTOs;

use App\Models\OrganizationCounsellor;
use App\Models\Request;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class OrganizationCounsellorCompensationDTO extends BaseDTO
{
    public ?User $user = null;

    public ?OrganizationCounsellor $organizationCounsellor = null;

    // SCRUM-148 (TT-6.4c): the pending Request being countered.
    public ?Request $request = null;

    public ?string $type = null;

    public ?int $amount = null;

    public ?string $currency = null;

    public ?int $percentage = null;

    public ?string $basis = null;

    // SCRUM-146 (TT-6.4c): optional override of config('organization.compensation_negotiation_default_expiry_days').
    public ?int $expiryDays = null;
}
