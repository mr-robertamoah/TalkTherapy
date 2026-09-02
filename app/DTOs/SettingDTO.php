<?php

namespace App\DTOs;

use App\Enums\SettingsEnum;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class SettingDTO extends BaseDTO
{
    public ?User $user = null;

    public ?SettingsEnum $key = null;

    public ?string $value = null;
}
