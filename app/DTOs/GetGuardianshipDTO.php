<?php

namespace App\DTOs;

use App\Models\Guardianship;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetGuardianshipDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Guardianship $guardianship = null;
}