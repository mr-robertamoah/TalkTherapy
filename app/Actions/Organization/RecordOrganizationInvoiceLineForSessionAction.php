<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Therapy\GetTherapyClientsAction;
use App\Actions\Transaction\GetPayableAmountAction;
use App\Actions\Transaction\ResolveCounsellorListedAmountAction;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
    // "organisation id:counsellor id" => their current terms, or null -- for this session.
    private array $terms = [];

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
        $therapy = $session->therapy() ?? $session->groupTherapy();

        // A free group has nothing for a retainer to pay (its members are never asked to pay either).
        if (! $therapy || ($therapy instanceof GroupTherapy && $therapy->payment_type !== TherapyPaymentTypeEnum::paid->value)) {
            return;
        }

        // SCRUM-420 (security review): a session is billed once, for who was in it then. Ending a session
        // that's already held runs this again, and a group's members and counsellors may have changed since
        // -- the line key alone would bill the newcomers for a session they weren't in.
        if (OrganizationInvoiceLine::query()->where('session_id', $session->id)->exists()) {
            return;
        }

        // SCRUM-420: who the retainer may cover -- a therapy's client, or each member of a group (its user
        // creator and current members, never one of its own counsellors), each with the organisation that
        // covers them. Therapy::addedby is polymorphic (User or Counsellor) -- an org retainer covers a
        // member, not a counsellor-initiated engagement.
        $retainer = GetRetainerCoveringOrganizationAction::new();
        $clients = GetTherapyClientsAction::new()->execute($therapy, $session->end_time);
        $covered = $clients
            ->map(fn (User $client, int $seat) => ['client' => $client, 'seat' => $seat, 'organization' => $retainer->execute($therapy, $client)])
            ->filter(fn (array $entry) => $entry['organization'] !== null)
            ->values();

        if ($covered->isEmpty()) {
            return;
        }

        $context = ['session_id' => $session->id, 'therapy_id' => $therapy->id];
        $counsellors = $therapy instanceof GroupTherapy ? $this->groupCounsellorsOf($therapy, $session) : collect([$therapy->counsellor])->filter()->values();

        if ($counsellors->isEmpty()) {
            Log::warning('Cannot record a retainer invoice line -- therapy has no assigned counsellor.', $context + [
                'organization_id' => $covered->first()['organization']->id,
            ]);

            return;
        }

        $currency = GetPayableAmountAction::new()->execute($session)['currency'] ?? null;
        $counsellorListedAmount = ResolveCounsellorListedAmountAction::new()->execute($session);

        // Each member on their own: one that fails doesn't stop the others being billed.
        foreach ($covered as $entry) {
            try {
                $this->recordFor($session, $entry['client'], $entry['organization'], $counsellors, $counsellorListedAmount, $currency, $context, [$entry['seat'], $clients->count()]);
            } catch (Throwable $exception) {
                Log::warning('Unable to record an organization invoice line for a held session.', $context + [
                    'organization_id' => $entry['organization']->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    // One line per counsellor for this member, as ComputeOrganizationChargeBreakdownAction works them out (the same
    // rules as the organisation's card charge). A counsellor this organisation has no terms with is skipped (the
    // retainer covers a group through any one of its counsellors -- the payment gate's rule).
    private function recordFor(Session $session, User $client, Organization $organization, Collection $counsellors, ?int $counsellorListedAmount, ?string $currency, array $context, array $seat): void
    {
        $context += ['organization_id' => $organization->id];
        $termsFor = fn (Counsellor $counsellor) => $this->termsFor($counsellor, $organization, $context);

        if ($counsellors->filter($termsFor)->isEmpty()) {
            return;
        }

        if (! $currency) {
            Log::warning('Cannot record a retainer invoice line -- this session has no resolvable currency.', $context);

            return;
        }

        $breakdown = ComputeOrganizationChargeBreakdownAction::new()->execute($counsellors, $termsFor, $counsellorListedAmount, $seat);

        foreach ($breakdown['skipped'] as $skipped) {
            if ($skipped['reason'] === 'unable_to_compute') {
                Log::warning('Cannot record a retainer invoice line -- unable to compute the compensation-driven share.', $context + [
                    'counsellor_id' => $skipped['counsellor']->id,
                    'exception' => $skipped['message'],
                ]);
            }
        }

        if (! $breakdown['lines']) {
            return;
        }

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
        // the same org/currency/period reaching `held` concurrently).
        $invoice = OrganizationInvoice::createOrFirst([
            'organization_id' => $organization->id,
            'currency' => $currency,
            'period_start' => $periodStart,
        ], [
            'period_end' => $periodEnd,
            'status' => OrganizationInvoiceStatusEnum::open->value,
        ]);

        // Unique on (session_id, counsellor_id, user_id) at the DB level -- this hook's own
        // idempotency guard. ChangeSessionStatusAction's `held` branch is not guaranteed to fire
        // only once per session (a replayed confirm, a retried request), so this must be
        // createOrFirst, never a bare create().
        foreach ($breakdown['lines'] as $line) {
            $invoice->lines()->createOrFirst([
                'session_id' => $session->id,
                'counsellor_id' => $line['counsellor']->id,
                'user_id' => $client->id,
            ], [
                'net_amount' => $line['net'],
                'fee_amount' => $line['fee'],
                'currency' => $currency,
            ]);
        }
    }

    // A counsellor's current terms with this organisation, or null (warned once per organisation and counsellor:
    // every covered member of a group asks the same).
    private function termsFor(Counsellor $counsellor, Organization $organization, array $context): ?OrganizationCounsellorCompensation
    {
        $key = $organization->id.':'.$counsellor->id;

        if (! array_key_exists($key, $this->terms)) {
            $this->terms[$key] = GetActiveOrganizationCounsellorAction::new()->execute($counsellor, $organization)?->latestCompensation;

            if (! $this->terms[$key]) {
                Log::warning('Cannot record a retainer invoice line -- no active compensation terms with this organization.', $context + ['counsellor_id' => $counsellor->id]);
            }
        }

        return $this->terms[$key];
    }

    // A group's active counsellors who had joined it before the session ended -- its creator always had.
    private function groupCounsellorsOf(GroupTherapy $group, Session $session): Collection
    {
        return $group->activeCounsellors()
            ->reject(fn (Counsellor $counsellor) => $session->end_time && $counsellor->pivot?->created_at?->greaterThan($session->end_time))
            ->values();
    }
}
