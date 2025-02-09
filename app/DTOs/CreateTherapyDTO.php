<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateTherapyDTO extends BaseDTO
{
    public ?User $user = null;
    public ?Counsellor $counsellor = null;
    public ?Therapy $therapy = null;
    public ?string $name = null;
    public ?bool $isEmergency = null;
    public ?array $cases = null;
    public ?string $backgroundStory = null;
    public ?string $per = null;
    public ?string $currency = null;
    public ?float $inPersonAmount = null;
    public ?float $amount = null;
    public bool $public = false;
    public bool $allowInPerson = false;
    public bool $anonymous = false;
    public ?string $sessionType = null;
    public ?string $paymentType = null;
    public ?int $maxSessions = null;
}