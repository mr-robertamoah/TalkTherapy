<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class TherapyAssistanceRequestDTO extends BaseDTO
{
    public User|Counsellor|null $from = null;
    public User|Counsellor|array|null $to = null;
    public Therapy|GroupTherapy|null $for = null;
}