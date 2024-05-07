<?php

namespace App\DTOs;

use App\Models\HowTo;
use App\Models\User;
use Illuminate\Http\Request;
use MrRobertAmoah\DTO\BaseDTO;

class CreateHowToDTO extends BaseDTO
{
    public ?User $user = null;
    public ?HowTo $howTo = null;
    public ?string $name = null;
    public ?string $description = null;
    public ?string $page = null;
    public ?array $howToSteps = [];
    public ?array $addedHowToSteps = [];
    public ?array $deletedHowToSteps = [];
}