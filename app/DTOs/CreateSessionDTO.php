<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateSessionDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Session $session = null;
    public ?String $name = null;
    public ?String $landmark = null;
    public float|string|null $latitude = null;
    public float|string|null $longitude = null;
    public ?String $about = null;
    public Carbon|string|null $startTime = null;
    public Carbon|string|null $endTime = null;
    public Therapy|GroupTherapy|null $for = null;
    public ?String $type = null;
    public ?String $paymentType = null;
    public ?array $cases = null;
    public ?array $topics = null;
    public ?TherapyTopic $therapyTopic = null;
}