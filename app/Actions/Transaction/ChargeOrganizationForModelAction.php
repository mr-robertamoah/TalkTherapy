<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Actions\Organization\ComputeCounsellorCompensationShareAction;
use App\Actions\Organization\GetActiveOrganizationCounsellorAction;
use App\DTOs\TransactionDTO;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Transaction;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\RequestException;

// TT-7.3b-b/SCRUM-233: charges an organization the actual cost of a SINGLE Therapy/Session
// engagement with ONE counsellor -- computing the cost, charging the saved instrument, and
// recording a Transaction, all in one place, so TT-7.3b-c (pay-per-use collection) builds no
// independent implementation of that combination for its own per-session charges.
//
// Architect review (documentation/decision-log.md, 2026-09-04 SCRUM-233 entry): TT-7.3b-e
// (retainer settlement) does NOT reuse this action's cost-computation half as-is -- its `for` will
// be a whole settled invoice period (potentially many sessions, several counsellors), which this
// action's single-counsellor/single-listed-amount resolution has no defined behavior for. TT-7.3b-e
// must either extend this action with an invoice-aware branch or call the lower-level compensation
// primitives (ComputeCounsellorCompensationShareAction, GetActiveOrganizationCounsellorAction)
// directly per line item and only reuse this action's charge-and-record tail -- decide which when
// that ticket starts, not assumed here.
//
// Callers own their OWN eligibility gate (mirrors TransactionService::initiateCharge()'s own
// ensure-chain) before ever reaching this action -- this action only re-checks the hard
// preconditions that would otherwise crash uninformatively (an instrument to charge, compensation
// terms to compute against), not full business eligibility. Security-engineer review itemized the
// full checklist TT-7.3b-c/-e must each build before calling in (none of it is this action's job):
// payment_type=paid, per-alignment (Therapy-as-a-whole vs. per-Session), a supported currency, the
// member's actual org-membership/billing-mode relationship to the org being charged (mirrors
// EnsureOrganizationCanPayForModelAction's own checks for the existing checkout-redirect org-pay
// flow), AND duplicate-charge protection (RecordTransactionStatusAction's terminal-status guard
// only protects a Transaction row that already exists from regressing status, not from a second,
// independent call creating a second successful charge) -- mirrors EnsureCanInitiateChargeAction's
// role in the personal-pay flow.
//
// GroupTherapy is deliberately out of scope (rejected below), mirroring TT-7.5a/TT-7.5b's own
// precedent of excluding group therapy from payment-gating features it hasn't been extended to
// yet -- a group therapy can have several active counsellors, each with their own, potentially
// different, org compensation terms, and "the actual cost" has no defined meaning here yet.
class ChargeOrganizationForModelAction extends Action
{
    public function execute(TransactionDTO $dto): Transaction
    {
        if (is_null($dto->organization)) {
            throw new TransactionException('An organization is required to charge via the org-charge primitive.', 422);
        }

        // Reviewer finding: checked on the RESOLVED subject, not just $dto->for directly -- a
        // PER_SESSION session belonging to a GroupTherapy must be rejected exactly the same way,
        // not merely happen to fall through to the generic "no counsellor" exception below because
        // GroupTherapy currently has no singular counsellor() accessor for $therapy?->counsellor
        // to resolve.
        $therapy = $dto->for instanceof Session ? $dto->for->for : $dto->for;

        if ($dto->for instanceof GroupTherapy || $therapy instanceof GroupTherapy) {
            throw new TransactionException('Organization billing is not yet supported for group therapies.', 422);
        }

        $counsellor = $therapy?->counsellor;

        if (! $counsellor) {
            throw new TransactionException('This engagement has no assigned counsellor to bill via an organization.', 422);
        }

        $affiliation = GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $dto->organization);

        if (! $affiliation || ! $affiliation->latestCompensation) {
            throw new TransactionException('This counsellor has no active compensation terms with this organization.', 422);
        }

        $instrument = $dto->organization->paymentInstrument;

        if (! $instrument) {
            throw new TransactionException('This organization has no payment instrument on file.', 422);
        }

        $payable = GetPayableAmountAction::new()->execute($dto->for);
        $currency = $payable['currency'];
        // Minor units -- the counsellor's own listed rate for this specific engagement, used both
        // as ComputeCounsellorCompensationShareAction's COUNSELLOR_RATE basis AND as the platform
        // fee's own basis below (never the org-compensation share itself -- see the fee comment).
        $counsellorListedAmount = isset($payable['amount']) ? (int) round($payable['amount'] * 100) : null;

        $counsellorShare = ComputeCounsellorCompensationShareAction::new()->execute($affiliation->latestCompensation, $counsellorListedAmount);

        // Decision 2 (SCRUM-230 review): the platform fee is charged on the counsellor's own
        // LISTED rate -- the "value of the service" -- never on the org-compensation share itself.
        // This is what makes a FREE compensation arrangement resolve cleanly and non-arbitrarily:
        // a $0 share still means a nonzero fee ("the org is simply charged the platform fee
        // alone"), which a fee-on-share calculation could never produce. ComputePlatformFeeAction
        // is the same shared primitive GenerateCounsellorEarningsAction uses -- only the base
        // amount passed in differs between the two callers.
        $feeAmount = $counsellorListedAmount !== null ? ComputePlatformFeeAction::new()->execute($counsellorListedAmount) : 0;

        $minorUnitsAmount = $counsellorShare + $feeAmount;

        try {
            $response = PaystackClient::new()->chargeAuthorization([
                'authorization_code' => $instrument->authorization_code,
                'email' => $dto->user->email,
                'amount' => $minorUnitsAmount,
                'currency' => $currency,
            ]);
        } catch (RequestException $exception) {
            throw new TransactionException('Unable to charge the organization right now. Please try again shortly.', 502);
        }

        $transaction = Transaction::query()->create([
            'for_type' => $dto->for::class,
            'for_id' => $dto->for->id,
            'user_id' => $dto->user->id,
            'organization_id' => $dto->organization->id,
            'reference' => $response['data']['reference'],
            'amount' => $minorUnitsAmount,
            'currency' => $currency,
            'status' => TransactionStatusEnum::pending->value,
        ]);

        $transaction->statusHistories()->create([
            'status' => TransactionStatusEnum::pending->value,
            'source' => TransactionStatusSourceEnum::orgCharge->value,
            'message' => 'Organization charge initiated.',
        ]);

        // Unlike the checkout-redirect flow, chargeAuthorization() already returns a definitive
        // status in this same response -- a webhook may still arrive afterward too (Paystack fires
        // one for every charge regardless of how it started), but RecordTransactionStatusAction's
        // own terminal-status guard makes that a safe, idempotent no-op replay.
        $status = match ($response['data']['status'] ?? null) {
            'success' => TransactionStatusEnum::success->value,
            'failed' => TransactionStatusEnum::failed->value,
            'abandoned' => TransactionStatusEnum::abandoned->value,
            default => null,
        };

        if (is_null($status)) {
            return $transaction;
        }

        if ($status === TransactionStatusEnum::success->value) {
            EnsureTransactionAmountAndCurrencyMatchAction::new()->execute(
                $transaction,
                isset($response['data']['amount']) ? (int) $response['data']['amount'] : null,
                $response['data']['currency'] ?? null,
                TransactionStatusSourceEnum::orgCharge->value
            );
        }

        return RecordTransactionStatusAction::new()->execute(
            $transaction,
            $status,
            TransactionStatusSourceEnum::orgCharge->value,
            $response['data']['gateway_response'] ?? null,
            $response['data'] ?? null
        );
    }
}
