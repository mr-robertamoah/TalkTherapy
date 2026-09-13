<?php

namespace App\DTOs;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use MrRobertAmoah\DTO\BaseDTO;

class SubmitAgeVerificationDTO extends BaseDTO
{
    public ?User $user = null;

    public ?string $attestation = null;

    public ?UploadedFile $document = null;
}
