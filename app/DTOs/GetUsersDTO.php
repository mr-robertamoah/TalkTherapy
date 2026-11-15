<?php

namespace App\DTOs;

use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class GetUsersDTO extends BaseDTO
{
    public ?User $user = null;

    public ?string $like = null;

    // SCRUM-441: FOR_GUARDIAN -- only people who could be one.
    public const FOR_GUARDIAN = 'guardian';

    public ?string $for = null;
}
