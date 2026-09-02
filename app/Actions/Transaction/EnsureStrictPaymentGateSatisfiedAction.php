<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\GrantPaymentAccessDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\PaymentRequiredException;
use App\Models\PaymentAccessGrant;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-219/220 (TT-7.5a): the ONE shared payment-gate-satisfaction check -- extracted from
// EnsureUserHasAccessToTherapyAction (SCRUM-219, PER_THERAPY page-load case) so
// MessageService's session/topic/reply content checks (SCRUM-220) delegate to the identical
// logic rather than a second independent copy. Only ever meaningful for the therapy's own
// addedby (the paying client) -- callers must already have established that before calling this.
class EnsureStrictPaymentGateSatisfiedAction extends Action
{
    // $session, when provided, is the specific Session the content being checked belongs to --
    // required to satisfy a PER_SESSION-payable gate (the grant/transaction lookup is scoped to
    // that Session, not the parent Therapy). Omit it for the PER_THERAPY-payable case.
    public function execute(Therapy $therapy, User $user, ?Session $session = null): void
    {
        if (
            ! $therapy->strictPaymentGate ||
            $therapy->payment_type !== TherapyPaymentTypeEnum::paid->value
        ) {
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

    private function ensureGrantedOrPaid(User $user, Therapy|Session $payable): void
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
}
