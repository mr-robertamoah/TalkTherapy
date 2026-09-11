<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Actions\Organization\GetRetainerCoveringOrganizationAction;
use App\DTOs\GrantPaymentAccessDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\OrganizationBillingSuspendedException;
use App\Exceptions\PaymentRequiredException;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-219/220 (TT-7.5a): the ONE shared payment-gate-satisfaction check -- extracted from
// EnsureUserHasAccessToTherapyAction (SCRUM-219, PER_THERAPY page-load case) so
// MessageService's session/topic/reply content checks (SCRUM-220) delegate to the identical
// logic rather than a second independent copy. Only ever meaningful for the therapy's own
// addedby (the paying client) -- callers must already have established that before calling this.
//
// TT-7.5b-b2/SCRUM-266: widened to GroupTherapy -- "only ever meaningful for the addedby" above
// no longer holds for GroupTherapy, where every paying MEMBER (not just whoever created the
// group) has their own independent payment standing; callers are responsible for having
// established that $user is a member (or counsellor, who this never gates -- see caller) before
// calling this, same division of responsibility as before.
class EnsureStrictPaymentGateSatisfiedAction extends Action
{
    // $session, when provided, is the specific Session the content being checked belongs to --
    // required to satisfy a PER_SESSION-payable gate (the grant/transaction lookup is scoped to
    // that Session, not the parent Therapy). Omit it for the PER_THERAPY-payable case.
    public function execute(Therapy|GroupTherapy $therapy, User $user, ?Session $session = null): void
    {
        if (
            ! $therapy->strictPaymentGate ||
            $therapy->payment_type !== TherapyPaymentTypeEnum::paid->value
        ) {
            return;
        }

        // TT-7.3b-f1/SCRUM-237: a retainer-covered engagement never produces a Transaction at
        // all (EnsureOrganizationCanPayForModelAction rejects the per-transaction charge attempt
        // outright), so without this bypass a retainer-covered client on a strict-gated therapy
        // could never satisfy the checks below -- a permanent lockout with no way to ever clear
        // it. Access is immediate and unconditional here, matching "retainer, regardless of
        // usage" -- this is deliberately NOT a patch to EnsureOrganizationCanPayForModelAction,
        // whose rejection of that charge attempt stays correct.
        $coveringOrganization = $this->getRetainerCoveringOrganization($therapy, $user);

        if ($coveringOrganization) {
            // TT-7.3b-f2/SCRUM-238: layered on top of the unconditional bypass above, not a
            // replacement for it -- a suspended org's member must be BLOCKED here, never fall
            // through to the checks below, since a retainer-covered engagement has no personal
            // Transaction to satisfy them with (explicit product decision: no personal-pay
            // fallback -- responsibility stays with the org, not the member).
            if ($coveringOrganization->isBillingSuspended()) {
                throw new OrganizationBillingSuspendedException(
                    'Access is currently suspended because your organization has an overdue retainer invoice. Please contact your organization administrator.',
                    403
                );
            }

            return;
        }

        $per = data_get($therapy->payment_data, 'per');

        // A PER_THERAPY-payable therapy is ALWAYS gated on the therapy itself, regardless of
        // whether a specific Session also happens to be in context at this call site (e.g.
        // MessageService's getSessionMessages() always has a $session, but a PER_THERAPY-payable
        // therapy must still gate on the whole therapy, not that one session -- otherwise its
        // chat would stay fully reachable even when strict-gated, the exact hole SCRUM-220
        // exists to close).
        if ($per === TherapyPerPaymentEnum::therapy->value) {
            $this->ensureGrantedOrPaid($user, $therapy);

            return;
        }

        // A PER_SESSION-payable therapy is gated per-Session -- with no concrete Session in
        // context (e.g. a Discussion-adjacent call site), there's nothing to gate here.
        if ($session && $per === TherapyPerPaymentEnum::session->value) {
            $this->ensureGrantedOrPaid($user, $session);
        }
    }

    private function ensureGrantedOrPaid(User $user, Therapy|GroupTherapy|Session $payable): void
    {
        $hasGrant = PaymentAccessGrant::query()
            ->where('user_id', $user->id)
            ->where('for_type', $payable::class)
            ->where('for_id', $payable->id)
            ->exists();

        if ($hasGrant) {
            return;
        }

        $successfulTransaction = $payable->transactions()
            ->where('user_id', $user->id)
            ->where('status', TransactionStatusEnum::success->value)
            ->latest('created_at')
            ->first();

        if ($successfulTransaction) {
            GrantPaymentAccessAction::new()->execute(GrantPaymentAccessDTO::new()->fromArray([
                'user' => $user,
                'for' => $payable,
                'transaction' => $successfulTransaction,
            ]));

            return;
        }

        throw new PaymentRequiredException('Payment is required to access this content.', 402);
    }

    // Resolves the org covering $user's membership on a RETAINER basis for $therapy's
    // counsellor, when one exists -- i.e. this specific engagement is meant to be settled
    // through that org's periodic invoicing (TT-7.3b-e), never a per-transaction charge, so it
    // must never be blocked on one existing. Returns the Organization (not just a bool) so the
    // caller can also check its billing-suspension standing (TT-7.3b-f2).
    private function getRetainerCoveringOrganization(Therapy|GroupTherapy $therapy, User $user): ?Organization
    {
        return GetRetainerCoveringOrganizationAction::new()->execute($therapy, $user);
    }
}
