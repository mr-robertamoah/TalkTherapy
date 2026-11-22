<?php

namespace App\DTOs;

use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

// TT-7.10b/SCRUM-468: who is acting on a commission-free period, and on which one. TT-7.11e/SCRUM-475: a counsellor's
// own waiver also names the counsellor (from the route) and, optionally, the approved recognition it is linked to.
class PlatformFeeWaiverDTO extends BaseDTO
{
    public ?User $user = null;

    // The period's id; PlatformFeeWaiverService looks it up (locked) itself.
    public ?int $waiverId = null;

    // The counsellor whose waiver it is (null: platform-wide).
    public ?int $counsellorId = null;

    public ?int $recognitionId = null;
}
