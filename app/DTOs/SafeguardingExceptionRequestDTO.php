<?php

namespace App\DTOs;

use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

// SCRUM-381: a counsellor asking the super administrator for a safeguarding exception for their
// paused client (a 1:1) or group member ($memberKey, from the group page's pausedMembers).
class SafeguardingExceptionRequestDTO extends BaseDTO
{
    public ?User $user = null;

    public Therapy|GroupTherapy|null $therapy = null;

    public ?string $memberKey = null;

    public ?string $reason = null;

    public ?string $proposedEndsAt = null;
}
