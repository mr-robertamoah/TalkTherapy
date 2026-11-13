<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Actions\Organization\ComputeOrganizationChargeBreakdownAction;
use App\Actions\Organization\GetActiveOrganizationCounsellorAction;
use App\Actions\Therapy\GetTherapyClientsAction;
use App\DTOs\TransactionDTO;
use App\Enums\SessionStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\OrganizationPaymentInstrument;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// TT-7.3b-b/SCRUM-233: charges an organization the actual cost of a SINGLE Therapy/Session
// engagement (SCRUM-420: or one member's place in a group) -- computing the cost, charging the saved instrument, and
// recording a Transaction, all in one place, so TT-7.3b-c (pay-per-use collection) builds no
// independent implementation of that combination for its own per-session charges.
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
// SCRUM-420: a GroupTherapy (or one of its sessions) is charged once per member -- each member's place, at the
// split ComputeOrganizationChargeBreakdownAction works out across its active counsellors. Every active counsellor
// must have terms with the organisation, or the charge is refused (no member's card is charged instead). The
// lock, the "already paid" check and the "still being confirmed" check are the member's own for a group: one
// member's charge never blocks another's.
class ChargeOrganizationForModelAction extends Action
{
    // SCRUM-248: every org charge's reference starts with this (the reconcile sweep selects by it).
    public const REFERENCE_PREFIX = 'org_charge_';

    public function execute(TransactionDTO $dto): Transaction
    {
        if (is_null($dto->organization)) {
            throw new TransactionException('An organization is required to charge via the org-charge primitive.', 422);
        }

        $therapy = ResolveTransactionSubjectAction::new()->execute($dto->for);

        $counsellors = $therapy instanceof GroupTherapy
            ? $therapy->activeCounsellors()->values()
            : collect([$therapy?->counsellor])->filter()->values();

        if ($counsellors->isEmpty()) {
            throw new TransactionException('This engagement has no assigned counsellor to bill via an organization.', 422);
        }

        $terms = $counsellors->mapWithKeys(fn (Counsellor $counsellor) => [
            $counsellor->id => GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $dto->organization)?->latestCompensation,
        ]);

        if ($terms->containsStrict(null)) {
            throw new TransactionException('This counsellor has no active compensation terms with this organization.', 422);
        }

        $instrument = $dto->organization->paymentInstrument;

        if (! $instrument) {
            throw new TransactionException('This organization has no payment instrument on file.', 422);
        }

        // Security-engineer finding: unlike the checkout-redirect flow (a link only -- real money
        // moves on a further, separate action on Paystack's own hosted page), this action moves
        // real money synchronously, in-request. Without a lock, two near-simultaneous calls for
        // the SAME engagement (a double-click, a client retry-on-timeout) could both pass the
        // caller's own duplicate-charge check before either commits a Transaction, charging the
        // org's card twice. Keyed on the exact subject being charged, held for the full
        // charge-and-record sequence below, and re-checked (not just locked) since the caller's
        // own check ran before this lock was acquired.
        $isGroup = $therapy instanceof GroupTherapy;
        $lock = Cache::lock('charge-organization-for:'.$dto->for::class.':'.$dto->for->id.($isGroup ? ':'.$dto->user->id : ''), 30);

        if (! $lock->get()) {
            throw new TransactionException('This is already being charged. Please wait a moment before trying again.', 429);
        }

        try {
            return $this->chargeAndRecord($dto, $therapy, $counsellors, $terms, $instrument);
        } finally {
            $lock->release();
        }
    }

    private function chargeAndRecord(TransactionDTO $dto, Therapy|GroupTherapy $therapy, Collection $counsellors, Collection $terms, OrganizationPaymentInstrument $instrument): Transaction
    {
        if ($this->transactionsOf($dto, $therapy)->where('status', TransactionStatusEnum::success->value)->exists()) {
            throw new TransactionException('This has already been paid for.', 422);
        }

        $this->ensureNoChargeIsStillBeingConfirmed($dto, $therapy);

        // SCRUM-420 (security review): a group session that didn't take place has nothing for the organisation to pay.
        if ($dto->for instanceof Session && $dto->for->status === SessionStatusEnum::failed->value) {
            throw new TransactionException('This session did not take place, so there is nothing for your organization to pay.', 422);
        }

        $currency = GetPayableAmountAction::new()->execute($dto->for)['currency'];
        // Minor units -- the counsellor's own listed rate for this specific engagement, used both
        // as ComputeCounsellorCompensationShareAction's COUNSELLOR_RATE basis AND as the platform
        // fee's own basis (never the org-compensation share itself).
        $counsellorListedAmount = ResolveCounsellorListedAmountAction::new()->execute($dto->for);

        // Decision 2 (SCRUM-230 review): the platform fee is charged on the counsellor's own
        // LISTED rate -- the "value of the service" -- never on the org-compensation share itself.
        // This is what makes a FREE compensation arrangement resolve cleanly and non-arbitrarily:
        // a $0 share still means a nonzero fee ("the org is simply charged the platform fee
        // alone"). SCRUM-420: worked out per counsellor by the breakdown action the retainer invoice
        // uses too; a group member's seat shares a fixed rate across the group's members.
        $breakdown = ComputeOrganizationChargeBreakdownAction::new()->execute(
            $counsellors,
            fn (Counsellor $counsellor) => $terms[$counsellor->id],
            $counsellorListedAmount,
            $this->seatOf($dto, $therapy)
        );

        if ($breakdown['skipped']) {
            throw new TransactionException('We could not work out this organization charge. Please contact support.', 422);
        }

        $minorUnitsAmount = array_sum(array_column($breakdown['lines'], 'net')) + array_sum(array_column($breakdown['lines'], 'fee'));

        // SCRUM-243: the Transaction row is created BEFORE the real charge, not after -- a
        // caller-generated reference (mirrors SettleOrganizationInvoiceAction/
        // ProcessOrganizationInvoiceSettlementJob's own identical, already-proven precedent: it
        // already passes its own locally-generated reference to this same chargeAuthorization()
        // endpoint) means a failure creating this row now fails CLOSED -- no charge is ever
        // attempted -- instead of the previous ordering, where a failure creating the row AFTER a
        // successful Paystack charge (a DB outage, a constraint violation) would have silently
        // moved real money with no local record left to reconcile against.
        $reference = self::REFERENCE_PREFIX.Str::uuid();

        $transaction = Transaction::query()->create([
            'for_type' => $dto->for::class,
            'for_id' => $dto->for->id,
            'user_id' => $dto->user->id,
            'organization_id' => $dto->organization->id,
            'reference' => $reference,
            'amount' => $minorUnitsAmount,
            'currency' => $currency,
            'status' => TransactionStatusEnum::pending->value,
            // SCRUM-420: what each counsellor is owed from this charge, as charged -- earnings are made from this.
            'breakdown' => ['lines' => array_map(fn (array $line) => [
                'counsellor_id' => $line['counsellor']->id,
                'net_amount' => $line['net'],
                'fee_amount' => $line['fee'],
            ], $breakdown['lines'])],
        ]);

        $transaction->statusHistories()->create([
            'status' => TransactionStatusEnum::pending->value,
            'source' => TransactionStatusSourceEnum::orgCharge->value,
            'message' => 'Organization charge initiated.',
        ]);

        try {
            $response = PaystackClient::new()->chargeAuthorization([
                'authorization_code' => $instrument->authorization_code,
                'email' => $dto->user->email,
                'amount' => $minorUnitsAmount,
                'currency' => $currency,
                'reference' => $reference,
            ]);
        } catch (HttpClientException $exception) { // SCRUM-272: an error response or no response at all
            // Left pending, not marked failed -- a webhook still arrives for every charge
            // regardless of how it started (this action's own comment below), so an ambiguous
            // HTTP-level failure here (was the charge actually received before the connection
            // dropped?) can still resolve correctly once it does, mirroring this same method's
            // existing handling of an unrecognized Paystack response status a few lines down.
            // Marking this failed outright here risks RecordTransactionStatusAction's own
            // terminal-status guard permanently blocking a later, genuine success webhook.
            // SCRUM-248 (security review): not "try again" -- the charge may have gone through; it is
            // confirmed automatically, and a retry meanwhile is refused (ensureNoChargeIsStillBeingConfirmed).
            throw new TransactionException('We could not confirm the charge with the payment provider. It will be checked automatically.', 502, $exception);
        }

        // Unlike the checkout-redirect flow, chargeAuthorization() already returns a definitive
        // status in this same response -- a webhook may still arrive afterward too (Paystack fires
        // one for every charge regardless of how it started), but RecordTransactionStatusAction's
        // own terminal-status guard makes that a safe, idempotent no-op replay.
        // SCRUM-244: 'abandoned' counts as failed here (no human present on a server-to-server
        // charge); RecordPaystackChargeOutcomeAction logs it distinctly.
        return RecordPaystackChargeOutcomeAction::new()->execute(
            $transaction,
            $response['data'] ?? [],
            TransactionStatusSourceEnum::orgCharge->value,
            abandonedIsFailure: true
        );
    }

    // SCRUM-248/272 (security review): a charge that timed out may still have gone through. Charging
    // again with a new reference could take the money twice, so an earlier charge still pending for
    // the same thing is settled first: while it is younger than the reconcile grace period Paystack
    // may simply not have it yet, so this refuses; once older, Paystack is asked, and only a definite
    // failure lets a new charge go ahead.
    private function ensureNoChargeIsStillBeingConfirmed(TransactionDTO $dto, Therapy|GroupTherapy $therapy): void
    {
        $pending = $this->transactionsOf($dto, $therapy)
            ->where('status', TransactionStatusEnum::pending->value)
            ->where('reference', 'like', self::REFERENCE_PREFIX.'%')
            ->get();

        // SCRUM-420 (security review): a checkout on the member's own card started moments ago may still be completed,
        // and the place would be paid twice. Older ones are abandoned checkouts (Paystack's own page was left).
        $personalCheckoutInProgress = $this->transactionsOf($dto, $therapy)
            ->where('user_id', $dto->user->id)
            ->where('status', TransactionStatusEnum::pending->value)
            ->where('reference', 'not like', self::REFERENCE_PREFIX.'%')
            ->where('created_at', '>', now()->subMinutes(config('organization.stale_charge_reconcile_after_minutes')))
            ->exists();

        if ($personalCheckoutInProgress) {
            throw new TransactionException('A payment you started for this is still open. Finish or leave it, then try again later.', 409);
        }

        foreach ($pending as $transaction) {
            $stillConfirming = new TransactionException('A previous charge for this is still being confirmed. It is checked automatically; please try again later.', 409);

            if ($transaction->created_at->gt(now()->subMinutes(config('organization.stale_charge_reconcile_after_minutes')))) {
                throw $stillConfirming;
            }

            try {
                $resolved = ResolveTransactionWithPaystackAction::new()->execute($transaction, TransactionStatusSourceEnum::reconcile->value, serverCharge: true);
            } catch (HttpClientException $exception) {
                throw $stillConfirming;
            }

            if ($resolved->status === TransactionStatusEnum::success->value) {
                throw new TransactionException('This has already been paid for.', 422);
            }

            if ($resolved->status !== TransactionStatusEnum::failed->value) {
                throw $stillConfirming;
            }
        }
    }

    // SCRUM-420: a group has a payer per member, so "already paid" and "still being confirmed" are the member's own
    // (EnsureCanInitiateChargeAction's rule); a therapy has one payer, so any charge for it counts.
    private function transactionsOf(TransactionDTO $dto, Therapy|GroupTherapy $therapy)
    {
        return $dto->for->transactions()
            ->when($therapy instanceof GroupTherapy, fn ($query) => $query->where('user_id', $dto->user->id));
    }

    // [this member's place, how many]: a fixed rate is shared across the group's places, by its maximum size (the
    // user's decision, 2026-11-13) -- members pay at different times, so counting who's there when each pays could
    // have the organisations together pay more than the rate. Refused if the payer isn't one of its members (the
    // caller's gate already checked this; a counsellor never pays into a group).
    private function seatOf(TransactionDTO $dto, Therapy|GroupTherapy $therapy): array
    {
        if ($therapy instanceof Therapy) {
            return [0, 1];
        }

        // For a session, its members are who had joined before it ended (the retainer's rule): someone who joined
        // later can't have their organisation pay for it.
        $clients = GetTherapyClientsAction::new()->execute($therapy, $dto->for instanceof Session ? $dto->for->end_time : null);
        $seat = $clients->search(fn (User $client) => $client->is($dto->user));

        if ($seat === false) {
            throw new TransactionException('You are not authorized to pay for this via this organization.', 403);
        }

        return [$seat, max($therapy->max_users, $clients->count())];
    }
}
