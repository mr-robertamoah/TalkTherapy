<?php

namespace App\DTOs;

use App\Models\Organization;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class OrganizationMemberRequestDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Organization $organization = null;

    public ?User $member = null;
}
