<?php

namespace App\DTOs;

use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

// TT-7.10b/SCRUM-468: who is acting on a platform-wide commission-free period, and on which one.
class PlatformFeeWaiverDTO extends BaseDTO
{
    public ?User $user = null;

    // The period's id; PlatformFeeWaiverService looks it up (locked) itself.
    public ?int $waiverId = null;
}
