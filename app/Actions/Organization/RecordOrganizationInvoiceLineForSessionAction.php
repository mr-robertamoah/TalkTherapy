<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Transaction\ComputePlatformFeeAction;
use App\Actions\Transaction\GetPayableAmountAction;
use App\Actions\Transaction\ResolveCounsellorListedAmountAction;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Collection;
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
        $clients = $this->clientsOf($therapy, $session);
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

    // One line per counsellor for this member. A group's price is split equally between its active counsellors
    // (any remainder to the first), and each is billed their own terms with this organisation on their part;
    // the platform fee is on the price of the parts billed, shared across the lines the same way. A rate that
    // isn't a share of the price -- fixed, or a percentage of a negotiated rate -- is the counsellor's pay for
    // the whole session (the user's decision, 2026-11-12), so each member's line carries an equal share of it
    // across everyone in the session ($seat: [this member's place, how many]; any remainder on the first). A
    // therapy has one counsellor and one client, so its one line is what it always was. A counsellor this
    // organisation has no terms with is skipped (the retainer covers a group through any one of its
    // counsellors -- the payment gate's rule).
    private function recordFor(Session $session, User $client, Organization $organization, Collection $counsellors, ?int $counsellorListedAmount, ?string $currency, array $context, array $seat): void
    {
        $context += ['organization_id' => $organization->id];
        $parts = $this->split($counsellorListedAmount, $counsellors->count());
        $billable = [];

        foreach ($counsellors as $index => $counsellor) {
            if ($compensation = $this->termsFor($counsellor, $organization, $context)) {
                $billable[] = ['counsellor' => $counsellor, 'compensation' => $compensation, 'part' => $parts[$index]];
            }
        }

        if (! $billable) {
            return;
        }

        if (! $currency) {
            Log::warning('Cannot record a retainer invoice line -- this session has no resolvable currency.', $context);

            return;
        }

        $shares = [];

        foreach ($billable as $entry) {
            try {
                $net = ComputeCounsellorCompensationShareAction::new()->execute($entry['compensation'], $entry['part']);
                $shares[] = $entry + ['net' => $this->isForTheSession($entry['compensation']) ? $this->split($net, $seat[1])[$seat[0]] : $net];
            } catch (InvalidArgumentException $exception) {
                Log::warning('Cannot record a retainer invoice line -- unable to compute the compensation-driven share.', $context + [
                    'counsellor_id' => $entry['counsellor']->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if (! $shares) {
            return;
        }

        // The fee on the parts billed: a counsellor this organisation doesn't pay takes their part's fee with them.
        $billed = $counsellorListedAmount !== null ? array_sum(array_column($shares, 'part')) : null;
        $fees = $this->split($billed !== null ? ComputePlatformFeeAction::new()->execute($billed) : 0, count($shares));

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
        foreach ($shares as $index => $share) {
            $invoice->lines()->createOrFirst([
                'session_id' => $session->id,
                'counsellor_id' => $share['counsellor']->id,
                'user_id' => $client->id,
            ], [
                'net_amount' => $share['net'],
                'fee_amount' => $fees[$index],
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

    // Whether these terms pay for the session whatever its price -- not a percentage of the price itself.
    private function isForTheSession(OrganizationCounsellorCompensation $compensation): bool
    {
        return $compensation->type === OrganizationCounsellorCompensationTypeEnum::fixed->value
            || ($compensation->type === OrganizationCounsellorCompensationTypeEnum::percentage->value
                && $compensation->basis === OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value);
    }

    // A therapy's client, or a group's user creator and its members -- the people its payment gate asks. A
    // retainer pays for a member's place in a session, as for a therapy's client: who joined before it ended
    // and hasn't left, whether or not they came (attendance isn't recorded).
    private function clientsOf(Therapy|GroupTherapy $therapy, Session $session): Collection
    {
        if ($therapy instanceof Therapy) {
            return collect([$therapy->addedby])->filter(fn ($addedby) => $addedby instanceof User);
        }

        $counsellorUserIds = $therapy->activeCounsellors()->pluck('user_id');

        $members = $therapy->users();

        // On the relation itself: when() would hand the closure the query, where wherePivot() means something else.
        if ($session->end_time) {
            $members->wherePivot('created_at', '<=', $session->end_time);
        }

        return $members->get()
            ->when($therapy->addedby instanceof User, fn (Collection $users) => $users->prepend($therapy->addedby))
            ->unique('id')
            ->reject(fn (User $user) => $counsellorUserIds->contains($user->id))
            ->values();
    }

    // $amount in $count whole parts, any remainder on the first; null stays null (no listed price).
    private function split(?int $amount, int $count): array
    {
        if ($amount === null) {
            return array_fill(0, $count, null);
        }

        $parts = array_fill(0, $count, intdiv($amount, $count));
        $parts[0] += $amount % $count;

        return $parts;
    }
}
