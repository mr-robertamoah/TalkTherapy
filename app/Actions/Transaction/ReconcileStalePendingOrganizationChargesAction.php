<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Support\SchedulerOnce;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-248: an org-financed pay-per-use charge (ChargeOrganizationForModelAction) is deliberately
// left pending when Paystack's answer was ambiguous, on the promise that its webhook will resolve
// it. If that webhook never arrives, nothing ever would. Every 15 minutes this asks Paystack about
// each charge pending longer than the grace period and records the answer the usual way. One that
// still can't be resolved after a day is logged for manual follow-up.
class ReconcileStalePendingOrganizationChargesAction extends Action
{
    public function execute(): array
    {
        $counts = ['resolved' => 0, 'stillPending' => 0, 'failed' => 0];
        $alertBefore = now()->subHours(config('organization.stale_charge_alert_after_hours'));

        Transaction::query()
            ->where('status', TransactionStatusEnum::pending->value)
            ->whereNotNull('organization_id')
            // SCRUM-420: a whole group, too -- the organisation's card pays for group places now.
            ->whereIn('for_type', [Therapy::class, Session::class, GroupTherapy::class])
            // Only real org charges (ChargeOrganizationForModelAction's references) -- not a member's
            // own card checkout for a group session, which also carries organization_id.
            ->where('reference', 'like', ChargeOrganizationForModelAction::REFERENCE_PREFIX.'%')
            ->where('created_at', '<=', now()->subMinutes(config('organization.stale_charge_reconcile_after_minutes')))
            ->lazyById()
            ->each(function (Transaction $transaction) use (&$counts, $alertBefore) {
                try {
                    $resolved = ResolveTransactionWithPaystackAction::new()->execute(
                        $transaction,
                        TransactionStatusSourceEnum::reconcile->value,
                        serverCharge: true
                    );
                } catch (Throwable $exception) {
                    // Paystack unreachable, or an amount/currency mismatch -- one row never stops the rest.
                    $counts['failed']++;
                    Log::warning('Could not reconcile a stale pending organization charge.', [
                        'transactionId' => $transaction->id,
                        'error' => class_basename($exception),
                    ]);
                    $this->alertIfOld($transaction, $alertBefore);

                    return;
                }

                if ($resolved->status !== TransactionStatusEnum::pending->value) {
                    $counts['resolved']++;

                    return;
                }

                $counts['stillPending']++;
                $this->alertIfOld($transaction, $alertBefore);
            });

        if (array_sum($counts) > 0) {
            Log::info('Stale pending organization charges reconciled.', $counts);
        }

        return $counts;
    }

    // The signal to follow up by hand. SCRUM-376: once a day per charge, not on every 15-minute run,
    // so a few stuck rows don't fill the log. The key lives in the scheduler's store, which the
    // deploy's cache:clear leaves alone.
    private function alertIfOld(Transaction $transaction, $alertBefore): void
    {
        $firstToday = fn () => SchedulerOnce::key("stale-org-charge-alert:{$transaction->id}:".now()->toDateString(), now()->addDay());

        if ($transaction->created_at->lte($alertBefore) && $firstToday()) {
            Log::warning('An organization charge is still unresolved after the alert period; follow up by hand.', [
                'transactionId' => $transaction->id,
                'reference' => $transaction->reference,
                'createdAt' => $transaction->created_at->toIso8601String(),
            ]);
        }
    }
}
