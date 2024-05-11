<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateDiscussionDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Discussion $discussion = null;
    public ?Session $session = null;
    public User|Counsellor|null $addedby = null;
    public Therapy|GroupTherapy|null $for = null;
    public ?string $name = null;
    public array|Collection|null $counsellors = null;
    public ?string $description = null;
    public ?string $status = null;
    public Carbon|string|null $startTime = null;
    public Carbon|string|null $endTime = null;
}