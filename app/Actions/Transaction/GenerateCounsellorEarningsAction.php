<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\CounsellorEarningShareBasisEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorEarningStatusSourceEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// TT-7.6b/SCRUM-226: called from RecordTransactionStatusAction, the existing single choke point
// for a transaction actually becoming SUCCESS -- so this only ever runs once per transaction in
// practice (that action's own terminal-status guard prevents a second success transition). The
// lockForUpdate()+earnings()->exists() check below is a defensive backstop against a theoretical
// webhook/verify race reaching RecordTransactionStatusAction near-simultaneously, not this
// ticket's job to fully close (that race, if real, predates and is outside TT-7.6b's scope).
class GenerateCounsellorEarningsAction extends Action
{
    // Deliberately a no-op for an org-financed transaction (organization_id set) -- splitting
    // that between the org and its affiliated counsellors is TT-7.3b's job, layered on top of
    // this payout mechanism once it exists. Paying a counsellor 100% of an org-financed
    // transaction's share here, because shareEqually/sharePercentage only govern same-therapy
    // multi-counsellor splits and say nothing about org-vs-counsellor splits, would be wrong.
    public function execute(Transaction $transaction): void
    {
        if ($transaction->organization_id !== null) {
            return;
        }

        // TT-7.3b-a/SCRUM-231 (security-engineer finding): an org-payment-instrument-registration
        // charge's subject IS an Organization (a different concept from organization_id above,
        // which means "an org financed this Therapy/Session/GroupTherapy payment") -- this was
        // already an accidental no-op (neither instanceof check below ever matched), but an
        // explicit guard makes that intentional rather than incidental, so a future change to the
        // $for-resolution logic below can't silently start generating bogus earnings off one.
        if ($transaction->for instanceof Organization) {
            return;
        }

        DB::transaction(function () use ($transaction) {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if (! $transaction || $transaction->earnings()->exists()) {
                return;
            }

            $for = $transaction->for instanceof Session ? $transaction->for->for : $transaction->for;

            if ($for instanceof Therapy) {
                $this->generateForIndividual($transaction, $for);

                return;
            }

            if ($for instanceof GroupTherapy) {
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

        $this->createEarning($transaction, $therapy->counsellor, $transaction->amount);
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

            $this->createEarning(
                $transaction,
                $counsellor,
                $gross,
                $shareBasis,
                $shareEqually ? null : $poolPercentage
            );
        }
    }

    private function createEarning(
        Transaction $transaction,
        Counsellor $counsellor,
        int $grossAmount,
        ?string $shareBasis = null,
        ?int $sharePercentage = null
    ): void {
        // TT-7.3b-b/SCRUM-233: extracted to ComputePlatformFeeAction -- the ONE place this
        // basis-points multiplication happens, shared with ChargeOrganizationForModelAction
        // (reviewer finding: this was previously duplicated verbatim between the two).
        $feeAmount = ComputePlatformFeeAction::new()->execute($grossAmount);

        $earning = $transaction->earnings()->create([
            'counsellor_id' => $counsellor->id,
            'gross_amount' => $grossAmount,
            'fee_amount' => $feeAmount,
            'net_amount' => $grossAmount - $feeAmount,
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
