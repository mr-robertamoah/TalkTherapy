<?php

namespace App\DTOs;

use App\Models\User;
use MrRobertAmoah\DTO\BaseDTO;

class TriggerPayoutDTO extends BaseDTO
{
    // The initiator -- either the counsellor themselves (self-service) or an admin
    // (on-behalf-of, gated by counsellorId being explicitly supplied).
    public ?User $user = null;

    // Explicit target for an admin-triggered payout -- null means "the initiator's own
    // counsellor" (self-service). A counsellor supplying their own counsellorId is not treated
    // any differently than omitting it; GetPayoutTargetCounsellorAction only trusts this field
    // when the initiator is an admin.
    public ?int $counsellorId = null;
}
