<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-7.7a/SCRUM-249: mirrors CounsellorPayoutStatusSourceEnum's minimal initiate/webhook shape --
// no 'verify' case, since this ticket builds no verify-callback fallback for refunds.
enum RefundStatusSourceEnum: string
{
    use EnumTrait;

    // The Refund row's own creation, at admin-approval time (RespondToRefundRequestAction) --
    // mirrors CounsellorPayoutStatusSourceEnum::initiate's role.
    case requested = 'REQUESTED';
    // TT-7.7d/SCRUM-252: ProcessRefundJob's own synchronous Paystack response, as opposed to a
    // later refund.processed/refund.failed webhook -- mirrors
    // CounsellorPayoutStatusSourceEnum::initiate's own identical distinction.
    case initiate = 'INITIATE';
    case webhook = 'WEBHOOK';

    // SCRUM-378: recorded from a retry's GET /refund/{id} lookup after Paystack refused the retried
    // refund (ProcessRefundJob) -- not from the refund call itself.
    case retryLookup = 'RETRY_LOOKUP';
}
