<?php

namespace App\DTOs;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use MrRobertAmoah\DTO\BaseDTO;

class OrganizationDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Organization $organization = null;

    public ?string $name = null;

    public ?string $legalName = null;

    public ?string $registrationNumber = null;

    public ?string $description = null;

    public ?string $email = null;

    public ?string $phone = null;

    public ?bool $isProvider = null;

    public ?bool $isConsumer = null;

    public ?bool $selfApplyEnabled = null;

    public ?UploadedFile $logo = null;

    public ?bool $deleteLogo = null;
}
