<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GetDiscussionsDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Discussion $discussion = null;
    public ?Counsellor $counsellor = null;
    public ?string $name = null;
    public Therapy|GroupTherapy|null $for = null;
}