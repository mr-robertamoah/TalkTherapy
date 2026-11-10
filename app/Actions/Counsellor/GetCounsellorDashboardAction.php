<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\Actions\GroupTherapy\PreloadGroupTherapyListAction;
use App\Actions\Payout\GetCounsellorPayoutOverviewAction;
use App\Actions\Request\PreloadRequestListAction;
use App\Actions\Session\GetCounsellorCalendarSessionsAction;
use App\Actions\Therapy\GetPublicTherapyFeedAction;
use App\Actions\Therapy\PreloadTherapyListAction;
use App\DTOs\GetCounsellorCalendarSessionsDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\TherapyStatusEnum;
use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\PublicTherapyResource;
use App\Http\Resources\RequestResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyMiniResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Support\CounsellorVerification;
use App\Support\PreferenceRanking;

// SCRUM-391 (the user's decisions): a counsellor's own dashboard, first version -- six blocks, each
// bounded (the first SHOWN items and how many there are in all), from the same actions and resources
// the rest of the app uses, so masking (anonymous clients, paused minors) is exactly as elsewhere.
// Only for the counsellor themselves ($viewer is their account).
class GetCounsellorDashboardAction extends Action
{
    public const SHOWN = 5;

    public function execute(Counsellor $counsellor, User $viewer): array
    {
        return [
            'needsAction' => $this->needsAction($counsellor),
            'upcomingSessions' => $this->upcomingSessions($viewer),
            'current' => $this->current($counsellor),
            'verification' => CounsellorVerification::projection($counsellor, $viewer),
            'earnings' => $this->earnings($counsellor),
            // Only while they can take anyone on (SCRUM-31).
            'couldHelp' => $counsellor->isVerified() ? $this->couldHelp($viewer) : null,
            // SCRUM-33: "available for urgent help" (it lapses on its own).
            'urgentHelp' => [
                'availableUntil' => $counsellor->isAvailableForUrgentHelp() ? $counsellor->urgent_help_available_until->toIso8601String() : null,
                'canBeAvailable' => $counsellor->isVerified() && ! $counsellor->isSuspended(),
                'hours' => (int) config('help.availability_hours'),
            ],
        ];
    }

    private function needsAction(Counsellor $counsellor): array
    {
        $query = Request::query()->awaitingCounsellor($counsellor);
        $requests = (clone $query)->latest('id')->limit(self::SHOWN)->get();
        PreloadRequestListAction::new()->execute($requests);

        return ['total' => $query->count(), 'items' => RequestResource::collection($requests)->resolve()];
    }

    private function upcomingSessions(User $viewer): array
    {
        $sessions = GetCounsellorCalendarSessionsAction::new()->execute(GetCounsellorCalendarSessionsDTO::new()->fromArray([
            'user' => $viewer,
            'startDate' => now(),
            'endDate' => now()->addDays(7),
        ]))->filter(fn ($session) => $session->start_time >= now())->values();

        return ['total' => $sessions->count(), 'items' => SessionResource::collection($sessions->take(self::SHOWN))->resolve()];
    }

    // The therapies they're assigned to and the groups they're active on or created -- not ended
    // (the user's decision: only active ones; pending invitations are under "needs your action").
    private function current(Counsellor $counsellor): array
    {
        $therapies = Therapy::query()->withoutHelpChats()
            ->where('counsellor_id', $counsellor->id)
            ->where('status', '!=', TherapyStatusEnum::ended->value);
        $groups = GroupTherapy::query()
            ->where('status', '!=', TherapyStatusEnum::ended->value)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('addedby_type', Counsellor::class)->where('addedby_id', $counsellor->id))
                ->orWhereHas('counsellors', fn ($query) => $query
                    ->whereKey($counsellor->id)
                    ->where('counsellor_group_therapy.state', CounsellorGroupTherapyStateEnum::active->value)));

        $therapyRows = PreloadTherapyListAction::new()->execute((clone $therapies)->latest('updated_at')->limit(self::SHOWN)->get());
        $groupRows = PreloadGroupTherapyListAction::new()->execute((clone $groups)->latest('updated_at')->limit(self::SHOWN)->get());

        return [
            'therapies' => ['total' => $therapies->count(), 'items' => TherapyMiniResource::collection($therapyRows)->resolve()],
            'groups' => ['total' => $groups->count(), 'items' => GroupTherapyMiniResource::collection($groupRows)->resolve()],
        ];
    }

    // At a glance: what they can withdraw now, in which currency, whether payouts are on hold, and
    // their last payout -- the full overview is on their profile.
    private function earnings(Counsellor $counsellor): array
    {
        $overview = GetCounsellorPayoutOverviewAction::new()->execute($counsellor);
        $last = $overview['payoutHistory']->resolve()[0] ?? null;

        return [
            'availableAmount' => $overview['availableAmount'],
            'currency' => $overview['currency'],
            'minimumPayoutAmount' => $overview['minimumPayoutAmount'],
            'onHold' => $overview['onHold'],
            'hasPayoutDestination' => (bool) $overview['payoutAccount'],
            'lastPayout' => $last,
        ];
    }

    // Public therapies and groups that need a counsellor, ranked by their profile (SCRUM-113), with
    // each card's "Offer to help".
    private function couldHelp(User $viewer): array
    {
        $page = GetPublicTherapyFeedAction::new()->execute($viewer, PreferenceRanking::seed(null), self::SHOWN, needsCounsellor: true);

        return ['total' => $page->total(), 'items' => PublicTherapyResource::collection($page->items())->resolve()];
    }
}
