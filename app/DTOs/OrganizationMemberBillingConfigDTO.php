<?php

namespace App\DTOs;

use App\Models\OrganizationMember;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class OrganizationMemberBillingConfigDTO extends BaseDTO
{
    public ?User $user = null;

    public ?OrganizationMember $organizationMember = null;

    public ?string $mode = null;

    public ?string $per = null;

    public ?bool $includeGroupTherapies = null;
}
