<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum CounsellorEarningStatusEnum: string
{
    use EnumTrait;

    // Available for payout.
    case pending = 'PENDING';
    // Claimed by an in-flight CounsellorPayout (TT-7.6c) -- this, not a boolean, is what a
    // concurrent payout trigger's lockForUpdate()->where('status', pending) claim relies on.
    case processing = 'PROCESSING';
    case paidOut = 'PAID_OUT';
    // Recorded in the audit trail (CounsellorEarningStatusHistory) when a Paystack transfer
    // fails/reverses, but the row's own `status` immediately moves back to `pending` afterward
    // (TT-7.6c) -- money never silently disappears from a counsellor's available balance.
    case failed = 'FAILED';
}
