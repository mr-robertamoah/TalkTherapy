<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class GroupTherapyDTO extends BaseDTO
{
    public ?Counsellor $counsellor = null;
    public ?User $user = null;
    public array $counsellorIds = [];
    public ?GroupTherapy $groupTherapy = null;
    public ?string $name = null;
    public ?bool $isEmergency = null;
    public ?array $cases = null;
    public ?string $about = null;
    public ?string $per = null;
    public ?string $currency = null;
    public ?float $inPersonAmount = null;
    public ?float $amount = null;
    public bool $public = false;
    public bool $allowInPerson = false;
    public bool $anonymous = false;
    public bool $allowAnyone = false;
    public ?string $sessionType = null;
    public ?string $paymentType = null;
    public ?int $maxSessions = null;
    public ?int $sharePercentage = null;
    public ?int $maxCounsellors = null;
    public ?int $maxUsers = null;
    public bool $shareEqually = false;
}