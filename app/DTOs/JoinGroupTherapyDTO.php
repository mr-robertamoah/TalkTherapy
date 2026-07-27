<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class JoinGroupTherapyDTO extends BaseDTO
{
    public ?User $user = null;

    public ?GroupTherapy $groupTherapy = null;

    public bool $anonymous = false;
}
