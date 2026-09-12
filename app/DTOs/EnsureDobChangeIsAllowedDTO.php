<?php

namespace App\DTOs;

use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class EnsureDobChangeIsAllowedDTO extends BaseDTO
{
    // The user whose dob is being changed.
    public ?User $user = null;

    // Whoever is making the edit -- the user themselves (self-service) or an admin.
    public ?User $actor = null;

    // The proposed new dob value, as submitted (already FormRequest-validated as a real date by
    // the time this DTO is built) -- null/empty clears the dob.
    public ?string $newDob = null;
}
