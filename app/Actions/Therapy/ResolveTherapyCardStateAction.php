<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyCardActionEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

// SCRUM-114: which button each card on a page of therapies and groups shows its viewer -- decided
// here, not again in the frontend, from the same rules the actions enforce (GroupTherapy::isFull,
// canTakeCounsellor, admitsNewMembers). Per viewer once, and per page at most two queries (their
// pending membership requests, and their counsellor profile's pending invites and offers). Rows
// must be preloaded (PreloadTherapyListAction / PreloadGroupTherapyListAction). The result is on
// each model's viewerCard: ['action' => ..., 'reason' => ?string].
class ResolveTherapyCardStateAction extends Action
{
    public function execute(Paginator|Collection|array $rows, ?User $viewer): Paginator|Collection|array
    {
        $models = collect($rows instanceof Paginator ? $rows->items() : $rows)
            ->filter(fn ($row) => $row instanceof Therapy || $row instanceof GroupTherapy);

        if ($models->isEmpty()) {
            return $rows;
        }

        $isAdmin = (bool) $viewer?->isAdmin();
        $counsellor = $viewer?->counsellor;
        $counsellorMayHelp = $counsellor && $counsellor->isVerified() && ! $counsellor->isSuspended();
        $groups = $models->filter(fn ($row) => $row instanceof GroupTherapy);

        $membershipAsked = $viewer && $groups->isNotEmpty()
            ? Request::query()->wherePending()->whereFrom($viewer)
                ->where('type', RequestTypeEnum::groupTherapyMembership->value)
                ->where('for_type', GroupTherapy::class)->whereIn('for_id', $groups->pluck('id'))
                ->pluck('for_id')->map(fn ($id) => (int) $id)->flip()
            : collect();

        // "type:id" => 'offer' (from them) or 'invite' (to them).
        $assistance = $counsellor
            ? Request::query()->wherePending()
                ->whereIn('type', Request::ASSISTANCE_TYPES)
                ->where(fn ($query) => $models->groupBy(fn ($row) => $row::class)->each(fn ($group, $type) => $query
                    ->orWhere(fn ($query) => $query->where('for_type', $type)->whereIn('for_id', $group->pluck('id')))))
                ->whereToOrFrom($counsellor)
                ->get(['type', 'for_type', 'for_id', 'from_type', 'from_id', 'to_type', 'to_id'])
                ->mapWithKeys(fn (Request $request) => [
                    "{$request->for_type}:{$request->for_id}" => $request->isAssistanceOffer() ? 'offer' : 'invite',
                ])
            : collect();

        foreach ($models as $row) {
            $pending = $assistance->get($row::class.':'.$row->id);
            $row->viewerCard = $row instanceof GroupTherapy
                ? $this->forGroup($row, $viewer, $isAdmin, $counsellor, $counsellorMayHelp, $pending, $membershipAsked->has($row->id))
                : $this->forTherapy($row, $viewer, $isAdmin, $counsellor, $counsellorMayHelp, $pending);
        }

        return $rows;
    }

    private function forTherapy(Therapy $therapy, ?User $viewer, bool $isAdmin, ?Counsellor $counsellor, bool $counsellorMayHelp, ?string $pending): array
    {
        if ($viewer && ($therapy->isUser($viewer) || ($counsellor && (int) $therapy->counsellor_id === $counsellor->id))) {
            return $this->card(TherapyCardActionEnum::open);
        }

        if ($counsellor) {
            if ($pending) {
                return $this->card($pending === 'invite' ? TherapyCardActionEnum::invited : TherapyCardActionEnum::offerSent);
            }

            if ($counsellorMayHelp && $therapy->public && is_null($therapy->counsellor_id) && $therapy->status !== TherapyStatusEnum::ended->value) {
                return $this->card(TherapyCardActionEnum::offer);
            }
        }

        return $this->card(TherapyCardActionEnum::view);
    }

    private function forGroup(GroupTherapy $group, ?User $viewer, bool $isAdmin, ?Counsellor $counsellor, bool $counsellorMayHelp, ?string $pending, bool $asked): array
    {
        $activeCounsellors = $group->activeCounsellorsForList();

        $isParticipant = $viewer && ($group->isCreatedBy($viewer) || $group->users->contains('id', $viewer->id)
            || ($counsellor && $activeCounsellors->contains('id', $counsellor->id)));

        if ($isParticipant) {
            return $this->card(TherapyCardActionEnum::open);
        }

        if (! $viewer) {
            return $this->card(TherapyCardActionEnum::view);
        }

        if ($group->status === TherapyStatusEnum::ended->value) {
            return $this->card(TherapyCardActionEnum::ended);
        }

        // A counsellor (an administrator with a counsellor profile too -- the offer endpoint allows
        // them, so the card does) offers to help rather than joining.
        if ($counsellor) {
            if ($pending) {
                return $this->card($pending === 'invite' ? TherapyCardActionEnum::invited : TherapyCardActionEnum::offerSent);
            }

            // A former member is refused by the offer itself (no query per row here).
            return $counsellorMayHelp && $group->public && $group->canTakeCounsellor($activeCounsellors->count())
                ? $this->card(TherapyCardActionEnum::offer)
                : $this->card(TherapyCardActionEnum::view);
        }

        if ($isAdmin) {
            return $this->card(TherapyCardActionEnum::view);
        }

        if ($asked) {
            return $this->card(TherapyCardActionEnum::requestSent);
        }

        if ($group->isFull($group->users->count())) {
            return $this->card(TherapyCardActionEnum::full);
        }

        if (! $group->admitsNewMembers($activeCounsellors)) {
            return $this->card(TherapyCardActionEnum::blocked, EnsureCounsellorMayTakeOnClientsAction::GROUP_NOT_ADMITTING);
        }

        return $this->card($group->allow_anyone ? TherapyCardActionEnum::join : TherapyCardActionEnum::ask);
    }

    private function card(TherapyCardActionEnum $action, ?string $reason = null): array
    {
        return ['action' => $action->value, 'reason' => $reason];
    }
}
