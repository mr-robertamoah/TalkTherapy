<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Language;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Post;
use App\Models\Profession;
use App\Models\Religion;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyCase;
use App\Models\TherapyTopic;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateStarDTO extends BaseDTO
{   
    public User|Counsellor|null $starredby = null;
    public User|Counsellor|null $starred = null;
    public Post|Language|TherapyCase|Discussion|Therapy|GroupTherapy|Religion|Profession|License|LicensingAuthority|Session|TherapyTopic|null $starreable = null;
    public ?String $type = null;
}