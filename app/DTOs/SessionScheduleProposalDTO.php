<?php

namespace App\DTOs;

use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Carbon\Carbon;
use MrRobertAmoah\DTO\BaseDTO;

class SessionScheduleProposalDTO extends BaseDTO
{
    public ?User $user = null;

    public ?Therapy $therapy = null;

    // The pending proposal being responded to/countered -- unused by the initial propose action
    // (TT-2.5a), read by accept/counter/reject (TT-2.5b).
    public ?Request $request = null;

    public Carbon|string|null $startTime = null;

    public Carbon|string|null $endTime = null;

    public ?string $name = null;

    public ?string $type = null;

    public ?string $paymentType = null;

    // Optional override of config('session_schedule_proposal.default_expiry_days').
    public ?int $expiryDays = null;
}
