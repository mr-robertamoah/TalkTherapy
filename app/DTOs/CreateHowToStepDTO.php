<?php

namespace App\DTOs;

use App\Models\HowTo;
use App\Models\HowToStep;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use MrRobertAmoah\DTO\BaseDTO;

class CreateHowToStepDTO extends BaseDTO
{
    public ?User $user = null;
    public ?HowTo $howTo = null;
    public ?HowToStep $howToStep = null;
    public ?string $name = null;
    public ?string $description = null;
    public ?int $position = null;
    public ?UploadedFile $file = null;
}