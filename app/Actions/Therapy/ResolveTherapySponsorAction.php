<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Actions\Organization\GetRetainerCoveringOrganizationAction;
use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

// SCRUM-180: which organisation, if any, pays for each therapy or group on a page -- for its client only.
// The "Sponsored" badge ("Paid by <org>") is never shown to anyone else: the organisation may be their
// employer or school. On each model's viewerSponsor: ['organizationName' => ...], or null.
//
// Sponsored means the organisation itself paid, in one of two ways (the user's decision, 2026-11-12):
//  (a) a therapy, or one of its sessions, charged to the organisation's own card
//      (ChargeOrganizationForModelAction -- its reference prefix, never organization_id alone: a group's
//      "organisation pays" still charges the member's card and keeps the organisation only as a label,
//      SCRUM-420), successful and not refunded. The most recent such payment names the organisation; a
//      deleted organisation is still named for what it paid.
//  (b) otherwise, a retainer covering them now (GetRetainerCoveringOrganizationAction, as the payment gate
//      asks it) -- for a paid therapy, and the only way for a group: the client of a group is each of its
//      members, and its user creator.
// A handful of queries per page, whatever its size: what it reads is loaded once for the page (kept when
// PreloadTherapyListAction / PreloadGroupTherapyListAction already did), then one for the charges and one
// for the viewer's retainers.
class ResolveTherapySponsorAction extends Action
{
    public function execute(Paginator|Collection|array $rows, ?User $viewer): Paginator|Collection|array
    {
        if (! $viewer) {
            return $rows;
        }

        $all = collect($rows instanceof Paginator ? $rows->items() : $rows);
        // What deciding reads, loaded once for the page (kept when the caller preloaded it).
        $therapies = EloquentCollection::make($all->filter(fn ($row) => $row instanceof Therapy)->values())->loadMissing('addedby');
        $groups = EloquentCollection::make($all->filter(fn ($row) => $row instanceof GroupTherapy)->values())->loadMissing(['addedby', 'users']);

        $models = $therapies->filter(fn (Therapy $therapy) => $therapy->isUser($viewer))
            ->concat($groups->filter(fn (GroupTherapy $group) => $this->isGroupClient($group, $viewer)));

        if ($models->isEmpty()) {
            return $rows;
        }

        $paidBy = $this->organizationCharges($models->filter(fn ($row) => $row instanceof Therapy), $viewer);

        // Keyed "class:id" => true: the paid ones (b) is asked for.
        $paid = $models
            ->filter(fn ($row) => ! $paidBy->has($this->key($row)) && $row->payment_type === TherapyPaymentTypeEnum::paid->value)
            ->mapWithKeys(fn ($row) => [$this->key($row) => true]);
        $retainer = GetRetainerCoveringOrganizationAction::new();
        $coverage = $paid->isNotEmpty() ? $retainer->coverageFor($viewer) : collect();

        if ($coverage->isNotEmpty()) {
            // The counsellors a retainer is checked against.
            $therapies->loadMissing('counsellor');
            $groups->loadMissing('activeCounsellorLinks');
        }

        foreach ($models as $row) {
            $organization = $paidBy->get($this->key($row))
                ?? ($coverage->isNotEmpty() && $paid->has($this->key($row)) ? $retainer->coveringOrganization($coverage, $retainer->counsellorIdsOf($row)) : null);

            $row->viewerSponsor = $organization ? ['organizationName' => $organization->name] : null;
        }

        return $rows;
    }

    // For one therapy or group's own page: its sponsor for this viewer, or null.
    public function forOne(Therapy|GroupTherapy $therapy, ?User $viewer): ?array
    {
        $this->execute(collect([$therapy]), $viewer);

        return $therapy->viewerSponsor;
    }

    // "therapy id" => the organisation on its latest qualifying charge, the therapy's or a session's.
    private function organizationCharges(Collection $therapies, User $viewer): Collection
    {
        if ($therapies->isEmpty()) {
            return collect();
        }

        $ids = $therapies->pluck('id');

        return Transaction::query()
            ->leftJoin('sessions', function ($join) {
                $join->on('sessions.id', '=', 'transactions.for_id')
                    ->where('transactions.for_type', Session::class)
                    ->where('sessions.for_type', Therapy::class);
            })
            // The client starts every charge for their therapy (EnsureCanPayForModelAction: participants only).
            ->where('transactions.user_id', $viewer->id)
            ->where('transactions.status', TransactionStatusEnum::success->value)
            ->whereNotNull('transactions.organization_id')
            ->where('transactions.reference', 'like', ChargeOrganizationForModelAction::REFERENCE_PREFIX.'%')
            ->whereDoesntHave('successfulRefund')
            ->where(function ($query) use ($ids) {
                $query->where(fn ($query) => $query->where('transactions.for_type', Therapy::class)->whereIn('transactions.for_id', $ids))
                    ->orWhere(fn ($query) => $query->where('transactions.for_type', Session::class)->whereIn('sessions.for_id', $ids));
            })
            ->with(['organization' => fn ($query) => $query->withTrashed()])
            ->orderByDesc('transactions.id')
            ->get(['transactions.id', 'transactions.organization_id', 'transactions.for_type', 'transactions.for_id', 'sessions.for_id as session_therapy_id'])
            ->groupBy(fn (Transaction $charge) => Therapy::class.':'.($charge->for_type === Therapy::class ? $charge->for_id : $charge->session_therapy_id))
            ->map(fn (Collection $charges) => $charges->first()->organization)
            ->filter(fn ($organization) => $organization instanceof Organization);
    }

    private function isGroupClient(GroupTherapy $group, User $viewer): bool
    {
        return ($group->addedby instanceof User && $group->addedby->is($viewer))
            || $group->users->contains('id', $viewer->id);
    }

    private function key(Therapy|GroupTherapy $row): string
    {
        return $row::class.':'.$row->id;
    }
}
