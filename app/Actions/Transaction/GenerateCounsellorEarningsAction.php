<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Actions\Organization\ComputeCounsellorCompensationShareAction;
use App\Actions\Organization\GetActiveOrganizationCounsellorAction;
use App\Enums\CounsellorEarningShareBasisEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorEarningStatusSourceEnum;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\Therapy;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

// TT-7.6b/SCRUM-226: called from RecordTransactionStatusAction, the existing single choke point
// for a transaction actually becoming SUCCESS -- so this only ever runs once per transaction in
// practice (that action's own terminal-status guard prevents a second success transition). The
// lockForUpdate()+earnings()->exists() check below is a defensive backstop against a theoretical
// webhook/verify race reaching RecordTransactionStatusAction near-simultaneously, not this
// ticket's job to fully close (that race, if real, predates and is outside TT-7.6b's scope).
class GenerateCounsellorEarningsAction extends Action
{
    // TT-7.3b-a/SCRUM-231 (security-engineer finding): an org-payment-instrument-registration
    // charge's subject IS an Organization (a different concept from organization_id below, which
    // means "an org financed this Therapy/Session/GroupTherapy payment") -- this was already an
    // accidental no-op (neither instanceof check below ever matched), but an explicit guard makes
    // that intentional rather than incidental, so a future change to the $for-resolution logic
    // below can't silently start generating bogus earnings off one.
    public function execute(Transaction $transaction): void
    {
        if ($transaction->for instanceof Organization) {
            return;
        }

        DB::transaction(function () use ($transaction) {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if (! $transaction) {
                return;
            }

            // TT-7.3b-e/SCRUM-236: checked BEFORE ResolveTransactionSubjectAction below, whose own
            // parameter type (Therapy|GroupTherapy|Session|null) has no OrganizationInvoice case --
            // passing one through would be a hard TypeError, not a graceful fallthrough. This
            // branch also skips the generic `$transaction->earnings()->exists()` idempotency guard
            // every other branch relies on: ONE settlement transaction fans out into MANY earnings
            // (one per line, potentially several per counsellor), so "any earning already exists
            // for this transaction" would wrongly block every later line -- generateForSettledInvoice
            // has its own, per-line idempotency guard instead (organization_invoice_line_id).
            if ($transaction->for instanceof OrganizationInvoice) {
                $this->generateForSettledInvoice($transaction, $transaction->for);

                return;
            }

            if ($transaction->earnings()->exists()) {
                return;
            }

            $for = ResolveTransactionSubjectAction::new()->execute($transaction->for);

            if ($for instanceof Therapy) {
                // TT-7.3b-d/SCRUM-235: this used to be a blanket no-op for every org-financed
                // transaction ($transaction->organization_id !== null) -- fully fixed now for the
                // individual-Therapy/Session case (the live bug this ticket exists to close).
                if ($transaction->organization_id !== null) {
                    $this->generateForOrgFinancedIndividual($transaction, $for);
                } else {
                    $this->generateForIndividual($transaction, $for);
                }

                return;
            }

            if ($for instanceof GroupTherapy) {
                // Deliberately still a no-op for an org-financed GroupTherapy -- carried forward
                // from TT-7.3b-b/-c's own scope boundary (GroupTherapy org billing was never
                // built: this transaction's `amount` is the client's own listed price, charged to
                // their personal card with organization_id set as pure attribution, not the
                // fee+share figure TT-7.3b-b computes for an individual engagement -- the formula
                // below does not apply to it). Paying a counsellor 100% of it here, because
                // shareEqually/sharePercentage only govern same-therapy multi-counsellor splits
                // and say nothing about org-vs-counsellor splits, would still be wrong.
                if ($transaction->organization_id !== null) {
                    return;
                }

                $this->generateForGroup($transaction, $for);
            }
        });
    }

    private function generateForIndividual(Transaction $transaction, Therapy $therapy): void
    {
        if (! $therapy->counsellor) {
            Log::warning('Cannot generate counsellor earnings -- therapy has no assigned counsellor.', [
                'transaction_id' => $transaction->id,
                'therapy_id' => $therapy->id,
            ]);

            return;
        }

        $feeAmount = ComputePlatformFeeAction::new()->execute($transaction->amount);

        $this->createEarning($transaction, $therapy->counsellor, $transaction->amount, $feeAmount, $transaction->amount - $feeAmount);
    }

    // TT-7.3b-d/SCRUM-235: the collection side (TT-7.3b-b/-c) already computed and charged the
    // org `transaction->amount` = the counsellor's compensation-driven share + the platform fee
    // (Decision 2, SCRUM-230 review) -- never a gross client price to deduct a fee FROM the way
    // the personal-pay formula above does. This re-derives ONLY the counsellor's share (net_amount)
    // via the same shared compensation primitives TT-7.3b-b itself uses, then takes fee_amount as
    // the REMAINDER of transaction->amount, not an independently recomputed figure -- this is what
    // mechanically guarantees this ticket's own regression invariant
    // (earning.net_amount + earning.fee_amount == transaction.amount) holds by construction, even
    // if platform_fee_percentage or the counsellor's compensation terms were to drift between
    // charge-time and this (near-immediate, same-request) earnings-generation call.
    private function generateForOrgFinancedIndividual(Transaction $transaction, Therapy $therapy): void
    {
        if (! $therapy->counsellor) {
            Log::warning('Cannot generate an org-financed counsellor earning -- therapy has no assigned counsellor.', [
                'transaction_id' => $transaction->id,
                'therapy_id' => $therapy->id,
            ]);

            return;
        }

        $affiliation = GetActiveOrganizationCounsellorAction::new()->execute($therapy->counsellor, $transaction->organization);

        if (! $affiliation || ! $affiliation->latestCompensation) {
            Log::warning('Cannot generate an org-financed counsellor earning -- no active compensation terms with this organization.', [
                'transaction_id' => $transaction->id,
                'therapy_id' => $therapy->id,
                'organization_id' => $transaction->organization_id,
            ]);

            return;
        }

        $counsellorListedAmount = ResolveCounsellorListedAmountAction::new()->execute($transaction->for);

        // Security-engineer finding: ComputeCounsellorCompensationShareAction throws for a
        // COUNSELLOR_RATE-basis compensation with no listed amount available -- unlike this
        // method's other two guard clauses, that's not this method's own precondition to
        // pre-check (the basis is a property of $affiliation->latestCompensation this method
        // doesn't otherwise inspect), so it's caught here instead of propagating. Uncaught, it
        // would roll back RecordTransactionStatusAction's whole DB::transaction() (including the
        // status update itself, by that action's own design) via the outer webhook/verify-callback
        // caller, permanently stranding an already-successfully-charged transaction as pending --
        // Paystack retrying the identical webhook would just hit the same exception again.
        try {
            $netAmount = ComputeCounsellorCompensationShareAction::new()->execute($affiliation->latestCompensation, $counsellorListedAmount);
        } catch (InvalidArgumentException $exception) {
            Log::warning('Cannot generate an org-financed counsellor earning -- unable to compute the compensation-driven share.', [
                'transaction_id' => $transaction->id,
                'therapy_id' => $therapy->id,
                'organization_id' => $transaction->organization_id,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        // Security-engineer finding: this remainder-based fee_amount mechanically satisfies the
        // regression invariant against whatever transaction->amount actually is, but says nothing
        // about whether $netAmount still matches what was ACTUALLY charged for at TT-7.3b-b's
        // charge time -- compensation terms (or platform_fee_percentage) could in principle change
        // in the window between that charge and this near-immediate earnings-generation call. A
        // drift silently reclassifies the difference as platform fee revenue with no other trace,
        // so it's surfaced here as a warning (not corrected -- there is no persisted charge-time
        // split to correct AGAINST) purely for finance/ops visibility, comparing against what the
        // SAME fee computation would independently yield off today's listed amount.
        $expectedFeeAmount = $counsellorListedAmount !== null ? ComputePlatformFeeAction::new()->execute($counsellorListedAmount) : null;

        if ($expectedFeeAmount !== null && $transaction->amount - $netAmount !== $expectedFeeAmount) {
            Log::warning('Org-financed counsellor earning: recomputed compensation share does not match the amount implied by the original charge -- compensation terms or the platform fee may have changed since. Flagging for manual reconciliation.', [
                'transaction_id' => $transaction->id,
                'therapy_id' => $therapy->id,
                'organization_id' => $transaction->organization_id,
                'recomputed_net_amount' => $netAmount,
                'expected_fee_amount' => $expectedFeeAmount,
                'transaction_amount' => $transaction->amount,
            ]);
        }

        // Defensive clamp, not expected in practice (see the method-level comment on the
        // drift assumption): a negative fee_amount would mean the counsellor's recomputed share
        // now exceeds what the org was actually charged -- never silently pay out more than the
        // transaction actually collected.
        $netAmount = max(0, min($netAmount, $transaction->amount));
        $feeAmount = $transaction->amount - $netAmount;

        $this->createEarning($transaction, $therapy->counsellor, $transaction->amount, $feeAmount, $netAmount);
    }

    private function generateForGroup(Transaction $transaction, GroupTherapy $groupTherapy): void
    {
        $counsellors = $groupTherapy->activeCounsellors()->values();

        if ($counsellors->isEmpty()) {
            Log::warning('Cannot generate counsellor earnings -- group therapy has no active counsellors.', [
                'transaction_id' => $transaction->id,
                'group_therapy_id' => $groupTherapy->id,
            ]);

            return;
        }

        $shareEqually = (bool) data_get($groupTherapy->payment_data, 'shareEqually');

        // "What percentage will you give to the participating counsellors?" (GroupTherapyFormModal.vue)
        // -- the % of the total transaction allocated to the counsellor pool AS A WHOLE, not to
        // any one named counsellor (this codebase has never modeled a per-counsellor split within
        // that pool, only equal-vs-percentage-of-the-whole -- see this file's counterpart entry in
        // documentation/decision-log.md for the full reasoning). Defaults to the full amount if
        // payment_data is missing this (shouldn't happen for a paid group therapy per
        // EnsureTherapyDataIsValidAction's own validation, but a successful payment must never
        // end up with nobody entitled to any of it).
        $poolPercentage = $shareEqually ? 100 : (int) (data_get($groupTherapy->payment_data, 'sharePercentage') ?: 100);
        // Defensive clamp (security review): EnsureTherapyDataIsValidAction is the only current
        // writer of payment_data and already bounds this to 40-100/70-100, but this action
        // handles real money and must not silently over/under-allocate a transaction if that
        // invariant is ever bypassed by a future write path (an admin tool, a migration, tinker).
        $poolPercentage = max(0, min(100, $poolPercentage));

        $poolAmount = intdiv($transaction->amount * $poolPercentage, 100);

        $count = $counsellors->count();
        $baseShare = intdiv($poolAmount, $count);
        // Pesewas/cents left over from an uneven division are never simply dropped -- assigned to
        // the first counsellor rather than lost.
        $remainder = $poolAmount - ($baseShare * $count);

        $shareBasis = $shareEqually
            ? CounsellorEarningShareBasisEnum::equal->value
            : CounsellorEarningShareBasisEnum::percentage->value;

        foreach ($counsellors as $index => $counsellor) {
            $gross = $baseShare + ($index === 0 ? $remainder : 0);
            $feeAmount = ComputePlatformFeeAction::new()->execute($gross);

            $this->createEarning(
                $transaction,
                $counsellor,
                $gross,
                $feeAmount,
                $gross - $feeAmount,
                $shareBasis,
                $shareEqually ? null : $poolPercentage
            );
        }
    }

    // TT-7.3b-e/SCRUM-236: iterates every line of a settled retainer invoice, creating ONE
    // CounsellorEarning per line using that line's ALREADY-COMPUTED net_amount/fee_amount
    // directly -- no recomputation, unlike generateForOrgFinancedIndividual above, because these
    // figures were locked in at session-held time (RecordOrganizationInvoiceLineForSessionAction),
    // so the drift concern that method's own comment describes structurally cannot occur here.
    private function generateForSettledInvoice(Transaction $transaction, OrganizationInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if (! $line->counsellor) {
                Log::warning('Cannot generate a retainer counsellor earning -- invoice line has no assigned counsellor.', [
                    'transaction_id' => $transaction->id,
                    'organization_invoice_line_id' => $line->id,
                ]);

                continue;
            }

            // Per-line idempotency guard, not the transaction-level earnings()->exists() check
            // every other branch uses (skipped above) -- ONE settlement transaction fans out into
            // MANY lines, so "at least one earning already exists for this transaction" would
            // wrongly block every other line from ever being generated.
            if (CounsellorEarning::query()->where('organization_invoice_line_id', $line->id)->exists()) {
                continue;
            }

            // Architect finding (blocking): gross_amount here is THIS LINE's own total, never
            // $transaction->amount -- that column is the SUM across every line/every counsellor
            // for the whole settled period, not any one counsellor's gross.
            $this->createEarning(
                $transaction,
                $line->counsellor,
                $line->fee_amount + $line->net_amount,
                $line->fee_amount,
                $line->net_amount,
                organizationInvoiceLineId: $line->id
            );
        }
    }

    private function createEarning(
        Transaction $transaction,
        Counsellor $counsellor,
        int $grossAmount,
        int $feeAmount,
        int $netAmount,
        ?string $shareBasis = null,
        ?int $sharePercentage = null,
        ?int $organizationInvoiceLineId = null
    ): void {
        $earning = $transaction->earnings()->create([
            'counsellor_id' => $counsellor->id,
            'organization_invoice_line_id' => $organizationInvoiceLineId,
            'gross_amount' => $grossAmount,
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
            'currency' => $transaction->currency,
            'share_basis' => $shareBasis,
            'share_percentage' => $sharePercentage,
            'status' => CounsellorEarningStatusEnum::pending->value,
        ]);

        $earning->statusHistories()->create([
            'status' => CounsellorEarningStatusEnum::pending->value,
            'source' => CounsellorEarningStatusSourceEnum::generation->value,
        ]);
    }
}
