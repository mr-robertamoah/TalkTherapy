<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetTherapyDTO extends BaseDTO
{
    public ?User $user = null;
    public Therapy|GroupTherapy|null $therapy = null;
}