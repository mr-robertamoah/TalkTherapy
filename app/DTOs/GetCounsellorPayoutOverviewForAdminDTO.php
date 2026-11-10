<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class GetCounsellorPayoutOverviewForAdminDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Counsellor $counsellor = null;
}
