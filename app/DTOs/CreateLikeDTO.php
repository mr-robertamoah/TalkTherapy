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

    // SCRUM-354: public therapies and groups only -- EnsureLikeableExistsAction checks.
    public Counsellor|Post|Therapy|GroupTherapy|null $likeable = null;

    public ?Like $like = null;
}
