<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum CounsellorPayoutStatusEnum: string
{
    use EnumTrait;

    // Created and its earnings claimed, about to be (or in the process of being) sent to
    // Paystack -- expected to be a very short-lived state given the actual Transfer call happens
    // in the same queued job right after creation.
    case pending = 'PENDING';
    // Paystack's Transfer call has been made and accepted; awaiting final confirmation (a
    // synchronous terminal response, or a later transfer.success/transfer.failed/
    // transfer.reversed webhook -- whichever arrives first, per RecordCounsellorPayoutStatusAction's
    // idempotent terminal-status guard).
    case processing = 'PROCESSING';
    case succeeded = 'SUCCEEDED';
    // Terminal: the claimed CounsellorEarning rows are returned to `pending` when this is
    // recorded (RecordCounsellorPayoutStatusAction) -- money never silently disappears from a
    // counsellor's available balance.
    case failed = 'FAILED';
}
