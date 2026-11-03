<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// Mirrors TransactionStatusSourceEnum's role, for the payout-execution lifecycle. No 'verify'
// case (unlike TransactionStatusSourceEnum) -- this ticket builds no verify-callback fallback for
// transfers, only the initiate call and the webhook.
enum CounsellorPayoutStatusSourceEnum: string
{
    use EnumTrait;

    case initiate = 'INITIATE';
    case webhook = 'WEBHOOK';

    // SCRUM-378: recorded from a retry's GET /transfer/verify lookup after Paystack refused the
    // retried transfer (ProcessCounsellorPayoutJob) -- not from the transfer call itself.
    case retryLookup = 'RETRY_LOOKUP';
}
