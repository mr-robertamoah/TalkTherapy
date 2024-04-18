<?php

namespace App\DTOs;

use App\Models\Alert;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class AlertServiceDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Alert $alert = null;
    public ?string $status = null;
    public Therapy|GroupTherapy|null $alertable = null;
}