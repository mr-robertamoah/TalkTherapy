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
    // TT-7.3b-g/SCRUM-239: terminal -- an org-financed transaction's own refund reconciliation
    // (ReconcileOrgFinancedRefundAction) moves a still-`pending` earning here, explicitly
    // excluding it from future payout eligibility. Deliberately never applied to a `processing`
    // or already-`paidOut` earning -- both are already claimed by a CounsellorPayout whose own
    // later resolution (RecordCounsellorPayoutStatusAction) does a blanket, status-agnostic
    // update that would silently clobber a reversal, so those are flagged for manual
    // reconciliation instead, not auto-mutated.
    case reversed = 'REVERSED';
}
