<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Models\Organization;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

// TT-7.3b-a/SCRUM-231: called from RecordTransactionStatusAction (the ONE place a transaction
// actually transitions to SUCCESS, alongside GenerateCounsellorEarningsAction) whenever that
// transaction's subject is an Organization -- i.e. it's an org-payment-instrument-registration
// charge (InitiateOrganizationPaymentInstrumentRegistrationAction), never a regular
// Therapy/Session/GroupTherapy payment. This is genuinely the "no separate save/tokenize call
// needed" design (architect decision, SCRUM-230 review): a successful verify/webhook response
// already carries everything needed, and the ONLY new work is persisting it here.
class CaptureOrganizationPaymentInstrumentAction extends Action
{
    // $authorization is Paystack's own 'data.authorization' object from the verify/webhook
    // response -- identical shape whether the caller is VerifyPaystackTransactionAction or
    // ProcessPaystackWebhookJob, both of which already have it in scope at the point they call
    // RecordTransactionStatusAction.
    public function execute(Transaction $transaction, array $authorization): void
    {
        if (! $transaction->for instanceof Organization) {
            return;
        }

        $authorizationCode = $authorization['authorization_code'] ?? null;

        // Never persist a non-reusable authorization -- there would be nothing for a future
        // pay-per-use/retainer charge to actually charge against, and a stale one-off code
        // sitting in this table would look like a working instrument without being one.
        if (! $authorizationCode || ! ($authorization['reusable'] ?? false)) {
            Log::warning('Cannot register an organization payment instrument -- Paystack returned no reusable authorization.', [
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->for->id,
            ]);

            return;
        }

        // Security-engineer finding: the same physical card being verified for a SECOND,
        // different organization would otherwise hit the authorization_code unique constraint as
        // a raw, uncaught QueryException, rolling back the whole success recording (including the
        // status update) for a real charge that genuinely succeeded. Checked explicitly up front
        // instead, so this degrades to a safe no-op + warning log (same shape as the
        // non-reusable-authorization case above) rather than an uncoded 500.
        if (OrganizationPaymentInstrument::query()
            ->where('authorization_code', $authorizationCode)
            ->where('organization_id', '!=', $transaction->for->id)
            ->exists()) {
            Log::warning('Cannot register an organization payment instrument -- this card is already registered to a different organization.', [
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->for->id,
            ]);

            return;
        }

        $existing = OrganizationPaymentInstrument::query()->where('organization_id', $transaction->for->id)->first();

        OrganizationPaymentInstrument::query()->updateOrCreate(
            ['organization_id' => $transaction->for->id],
            [
                'authorization_code' => $authorizationCode,
                'masked_card_number' => '**** '.($authorization['last4'] ?? '????'),
                'card_type' => $authorization['card_type'] ?? null,
                'bank' => $authorization['bank'] ?? null,
                'exp_month' => $authorization['exp_month'] ?? null,
                'exp_year' => $authorization['exp_year'] ?? null,
                'currency' => $transaction->currency,
                // Owed back to the org as a credit against its first real invoice once TT-7.3b-e's
                // invoicing exists -- this verification charge was never "kept" revenue. Reviewer
                // finding: replacing this row in place (a re-registration, e.g. a declined/expired
                // card) must never simply overwrite a still-outstanding prior credit with the new
                // one -- accumulated below when the currency matches, since both credits are still
                // genuinely owed. A currency mismatch across registrations can't be summed as raw
                // minor units, so it's logged instead (a documented gap for TT-7.3b-e to reconcile
                // manually) rather than silently dropped either way.
                'pending_credit_amount' => $this->accumulatedPendingCredit($existing, $transaction),
            ]
        );
    }

    private function accumulatedPendingCredit(?OrganizationPaymentInstrument $existing, Transaction $transaction): int
    {
        if (! $existing || ! $existing->pending_credit_amount) {
            return $transaction->amount;
        }

        if ($existing->currency === $transaction->currency) {
            return $existing->pending_credit_amount + $transaction->amount;
        }

        Log::warning('Organization payment instrument replaced with a different verification currency -- the prior pending credit could not be carried over automatically and needs manual reconciliation.', [
            'organization_id' => $transaction->for->id,
            'previous_currency' => $existing->currency,
            'previous_pending_credit_amount' => $existing->pending_credit_amount,
            'new_currency' => $transaction->currency,
        ]);

        return $transaction->amount;
    }
}
