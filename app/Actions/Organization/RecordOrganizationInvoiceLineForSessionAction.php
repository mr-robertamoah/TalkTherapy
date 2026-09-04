<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Transaction\ComputePlatformFeeAction;
use App\Actions\Transaction\GetPayableAmountAction;
use App\Actions\Transaction\ResolveCounsellorListedAmountAction;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Models\OrganizationInvoice;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

// TT-7.3b-e/SCRUM-236: hooked into ChangeSessionStatusAction's `held` transition (see the
// comment there) -- the ONE place a retainer-covered Session's billable value gets computed AND
// PERSISTED, at the moment the clinical event happens, not lazily recomputed at settlement time.
// Architect decision: unlike GenerateCounsellorEarningsAction's own same-request recomputation
// window, the gap here between a session occurring and month-end settlement can be weeks --
// locking the amount in here avoids that class of drift entirely rather than accepting and
// merely logging it the way TT-7.3b-d does for pay-per-use.
//
// This action must NEVER throw: a session reaching `held` is a clinical fact that must always be
// recorded regardless of the org's billing hygiene (unlike ChargeOrganizationForModelAction,
// invoked by someone actively trying to pay who CAN be told what's missing). Every precondition
// failure below is a logged warning and a skip, never an exception -- and the whole body is
// wrapped defensively so an unexpected error here can't ever propagate into the caller's own
// session-status transition.
class RecordOrganizationInvoiceLineForSessionAction extends Action
{
    public function execute(Session $session): void
    {
        try {
            $this->record($session);
        } catch (Throwable $exception) {
            Log::warning('Unable to record an organization invoice line for a held session.', [
                'session_id' => $session->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function record(Session $session): void
    {
        $therapy = $session->therapy();

        if (! $therapy) {
            return;
        }

        // Therapy::addedby is polymorphic (User or Counsellor) -- GetRetainerCoveringOrganizationAction
        // only has a defined meaning for a User-added therapy (an org retainer covers a member,
        // not a counsellor-initiated engagement).
        if (! $therapy->addedby instanceof User) {
            return;
        }

        $organization = GetRetainerCoveringOrganizationAction::new()->execute($therapy, $therapy->addedby);

        if (! $organization) {
            return;
        }

        $counsellor = $therapy->counsellor;

        if (! $counsellor) {
            Log::warning('Cannot record a retainer invoice line -- therapy has no assigned counsellor.', [
                'session_id' => $session->id,
                'therapy_id' => $therapy->id,
                'organization_id' => $organization->id,
            ]);

            return;
        }

        $affiliation = GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $organization);

        if (! $affiliation || ! $affiliation->latestCompensation) {
            Log::warning('Cannot record a retainer invoice line -- no active compensation terms with this organization.', [
                'session_id' => $session->id,
                'therapy_id' => $therapy->id,
                'organization_id' => $organization->id,
            ]);

            return;
        }

        $currency = GetPayableAmountAction::new()->execute($session)['currency'] ?? null;

        if (! $currency) {
            Log::warning('Cannot record a retainer invoice line -- this session has no resolvable currency.', [
                'session_id' => $session->id,
                'therapy_id' => $therapy->id,
                'organization_id' => $organization->id,
            ]);

            return;
        }

        $counsellorListedAmount = ResolveCounsellorListedAmountAction::new()->execute($session);

        try {
            $netAmount = ComputeCounsellorCompensationShareAction::new()->execute($affiliation->latestCompensation, $counsellorListedAmount);
        } catch (InvalidArgumentException $exception) {
            Log::warning('Cannot record a retainer invoice line -- unable to compute the compensation-driven share.', [
                'session_id' => $session->id,
                'therapy_id' => $therapy->id,
                'organization_id' => $organization->id,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        $feeAmount = $counsellorListedAmount !== null ? ComputePlatformFeeAction::new()->execute($counsellorListedAmount) : 0;

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        // Lookup key is (organization_id, currency, period_start), NOT just (organization_id,
        // period_start) -- architect finding: different counsellors covered by the same org can
        // have listed rates in different currencies, and summing mixed-currency amounts into one
        // invoice would be a real bug. Mirrors TriggerCounsellorPayoutAction's own
        // currency-scoped claiming.
        //
        // createOrFirst(), not firstOrCreate() -- mirrors GrantPaymentAccessAction's own
        // documented convention for a race against this exact unique constraint (two sessions for
        // the same org/currency/period reaching `held` concurrently). Laravel's firstOrCreate()
        // already delegates to createOrFirst() on a miss (verified against this app's installed
        // Laravel version), so this is behaviorally identical -- calling it directly just makes
        // the race-safety intentional rather than incidental to a future reader.
        $invoice = OrganizationInvoice::createOrFirst([
            'organization_id' => $organization->id,
            'currency' => $currency,
            'period_start' => $periodStart,
        ], [
            'period_end' => $periodEnd,
            'status' => OrganizationInvoiceStatusEnum::open->value,
        ]);

        // Unique on session_id at the DB level -- this hook's own idempotency guard.
        // ChangeSessionStatusAction's `held` branch is not guaranteed to fire only once per
        // session (a replayed confirm, a retried request), so this must be createOrFirst, never a
        // bare create().
        $invoice->lines()->createOrFirst([
            'session_id' => $session->id,
        ], [
            'counsellor_id' => $counsellor->id,
            'net_amount' => $netAmount,
            'fee_amount' => $feeAmount,
            'currency' => $currency,
        ]);
    }
}
