<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-7.7a/SCRUM-249: mirrors TransactionStatusEnum's own success/failed vocabulary rather than
// CounsellorPayoutStatusEnum's "succeeded" -- a refund's terminal outcome is fundamentally about
// the SAME Paystack charge-reversal concept `transactions.status` already tracks, just in the
// opposite direction.
enum RefundStatusEnum: string
{
    use EnumTrait;

    // Created once an admin approves the client's refund request (RespondToRefundRequestAction)
    // -- the real Paystack refund call (TT-7.7d) hasn't been made yet.
    case pending = 'PENDING';
    // TT-7.7d's queued job has called Paystack's refund endpoint; awaiting a synchronous result
    // or a later refund.processed/refund.failed webhook, mirroring CounsellorPayout's own
    // pending->processing split for its Transfer call.
    case processing = 'PROCESSING';
    case success = 'SUCCESS';
    case failed = 'FAILED';
}
