<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetTherapyTopicsDTO extends BaseDTO
{
    public Therapy|GroupTherapy|null $therapy = null;
    public ?User $user = null;
    public String|null $name = null;
}