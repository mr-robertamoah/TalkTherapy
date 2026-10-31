<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Like;
use App\Models\Post;
use App\Models\Therapy;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class CreateLikeDTO extends BaseDTO
{
    public ?User $user = null;

    // SCRUM-349: no GroupTherapy until likes are authorized -- getLikes is public and would list who
    // liked a private/anonymous group (SCRUM-351).
    public Counsellor|Post|Therapy|null $likeable = null;

    public ?Like $like = null;
}
