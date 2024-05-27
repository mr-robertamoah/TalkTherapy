<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Link;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateLinkDTO extends BaseDTO
{
    public ?User $user = null;
    public User|Counsellor|null $addedby = null;
    public ?Link $link = null;
    public User|Counsellor|null $to = null;
    public User|Counsellor|Therapy|GroupTherapy|Discussion|null $for = null;
    public ?string $state = null;
    public ?string $type = null;
    public ?array $linksData = [];
}