<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Exceptions\TherapyException;
use App\Models\GroupTherapy;
use App\Models\User;

// TT-7.5b-b0/SCRUM-264: GroupTherapy supports several concurrently-ACTIVE counsellors, unlike
// individual Therapy's single `counsellor_id` -- TT-7.5a's EnsureCanSetStrictPaymentGateAction
// (single-counsellor-shaped) has no direct analogue here. Decision (user-approved 2026-09-11): any
// ACTIVE counsellor may toggle the group's payment-gate settings independently, matching the
// isCounsellor()/activeCounsellors() convention already used everywhere else in this model
// (earnings split, org-payer eligibility, TT-7.4d-d's roster gate) -- not a new, more restrictive
// authorization shape. Accepted trade-off (pre-existing debt, not new): one active counsellor can
// silently override another's setting, with no audit trail -- individual Therapy's own
// UpdateTherapyAction has no change-tracking on payment_data either.
//
// Deliberately reuses GroupTherapy::isCounsellor(), which already implements this exact
// "addedby-as-counsellor OR active pivot row" rule, rather than re-querying activeCounsellors()
// separately. Reviewer finding: isCounsellor() and activeCounsellors() are NOT fully equivalent --
// isCounsellor()'s addedby-as-counsellor branch has no trashed check (addedby() is withTrashed()),
// while activeCounsellors() explicitly excludes a trashed addedby. This is safe here only because
// $user->counsellor (a plain, non-withTrashed hasOne) already returns null once that counsellor is
// soft-deleted, short-circuiting the `$user->counsellor &&` guard below before isCounsellor() is
// ever reached -- a future caller invoking isCounsellor() directly against a bare Counsellor model
// (bypassing the User relation) would NOT get that same protection for free.
//
// Deliberately takes a bare GroupTherapy + User, not a DTO -- unlike TT-7.5a's DTO-shaped
// equivalent, this action doesn't own the strictPaymentGate/allowFreeHistoricalAccess field names
// (that's TT-7.5b-b1's job); callers decide WHEN to invoke this (only once an existing GroupTherapy
// is having one of those fields explicitly changed) -- this action only answers WHO may do it.
//
// Security review precedent (mirrors TT-7.5a's own SCRUM-221 finding): deliberately no "value is
// already unchanged" short-circuit -- callers must invoke this whenever a gate-related field is
// explicitly provided, whether or not the value is actually changing, so an unauthorized caller
// can't use success-vs-422 as a boolean oracle for a group's current setting.
class EnsureCanSetGroupTherapyPaymentGateAction extends Action
{
    public function execute(GroupTherapy $groupTherapy, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if ($user->counsellor && $groupTherapy->isCounsellor($user->counsellor)) {
            return;
        }

        throw new TherapyException('Only an active counsellor on this group can change the payment gate settings.', 422);
    }
}
