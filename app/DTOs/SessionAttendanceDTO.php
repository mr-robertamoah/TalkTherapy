<?php

namespace App\DTOs;

use App\Models\Session;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

// SCRUM-445. $entries: the submitted register, [['key' => ..., 'status' => ...], ...] (validated by the request).
class SessionAttendanceDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Session $session = null;

    public ?array $entries = null;
}
