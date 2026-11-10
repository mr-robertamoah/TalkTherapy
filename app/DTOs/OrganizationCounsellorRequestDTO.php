<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class OrganizationCounsellorRequestDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Organization $organization = null;

    public ?Counsellor $counsellor = null;
}
