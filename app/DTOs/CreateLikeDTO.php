<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Like;
use App\Models\Post;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateLikeDTO extends BaseDTO
{
    public ?User $user = null;
    public Counsellor|Post|Therapy|GroupTherapy|null $likeable = null;
    public Like|null $like = null;
}