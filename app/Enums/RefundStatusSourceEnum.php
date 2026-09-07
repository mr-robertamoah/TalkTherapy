<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-7.7a/SCRUM-249: mirrors CounsellorPayoutStatusSourceEnum's minimal initiate/webhook shape --
// no 'verify' case, since this ticket builds no verify-callback fallback for refunds (TT-7.7d, if
// it needs one, extends this the same way TT-2.5b/SCRUM-244 etc. have extended sibling enums via
// a follow-up migration).
enum RefundStatusSourceEnum: string
{
    use EnumTrait;

    // The Refund row's own creation, at admin-approval time (RespondToRefundRequestAction) --
    // mirrors CounsellorPayoutStatusSourceEnum::initiate's role.
    case requested = 'REQUESTED';
    case webhook = 'WEBHOOK';
}
