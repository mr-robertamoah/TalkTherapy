<?php

namespace App\DTOs;

use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

// TT-7.11d2/SCRUM-474: a super administrator setting the daily star caps -- name (SettingsService::RECOGNITION_CAP_KEYS)
// => whole number.
class UpdateRecognitionCapsDTO extends BaseDTO
{
    public ?User $user = null;

    public array $caps = [];
}
