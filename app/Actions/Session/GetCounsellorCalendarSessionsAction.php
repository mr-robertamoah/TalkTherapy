<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\GetCounsellorCalendarSessionsDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use Carbon\Carbon;

class GetCounsellorCalendarSessionsAction extends Action
{
    // Unions Session rows across two structurally different relations -- Therapy has a single
    // counsellor_id, GroupTherapy has a many-counsellors pivot (plus, per GroupTherapy::isCounsellor(),
    // a Counsellor addedby also counts as one of its counsellors). No cross-therapy session
    // aggregation query existed before this (SCRUM-212) -- every existing SessionService method is
    // scoped to a single Therapy/GroupTherapy at a time.
    public function execute(GetCounsellorCalendarSessionsDTO $dto)
    {
        $counsellor = $dto->user->counsellor;

        $startDate = Carbon::parse($dto->startDate);
        $endDate = Carbon::parse($dto->endDate);

        // Every eager load here mirrors TT-7.4a's latestTransaction precedent, applied to BOTH
        // legs of the union -- easy to eager-load one leg and miss the other when merging two
        // query results. This is also the first place SessionResource is ever rendered in bulk
        // (every other caller shows one session, or a single therapy's own paginated list) --
        // topics/cases/therapyTopicSessions/addedby are per-SESSION fields SessionResource always
        // reads, previously only ever N+1-safe by accident (never eager-loaded, never noticed at
        // the small scale those other call sites render at).
        $with = [
            'latestTransaction',
            'topics',
            'cases',
            'therapyTopicSessions.therapyTopic',
            'addedby' => function ($morphTo) {
                $morphTo->morphWith([
                    Counsellor::class => ['user'],
                ]);
            },
            'for' => function ($morphTo) {
                // Nested down to user/avatarFile -- matches GetOrganizationCounsellorsAction's
                // identical precedent for avoiding CounsellorMiniResource's own N+1 (it reads
                // ->user and ->avatar, neither eager-loaded by counsellor()/counsellors() alone).
                // `addedby` needs its OWN nested morphWith (a polymorphic relation nested inside
                // this one) since it's a completely separate relation from counsellor(s) -- eager
                // loading the pivot-attached counsellors doesn't share an object instance with a
                // Counsellor addedby, even when they resolve to the same underlying row.
                $addedByMorphWith = fn ($nested) => $nested->morphWith([
                    Counsellor::class => ['user', 'avatarFile'],
                ]);

                $morphTo->morphWith([
                    Therapy::class => ['addedby' => $addedByMorphWith, 'counsellor.user', 'counsellor.avatarFile'],
                    GroupTherapy::class => ['addedby' => $addedByMorphWith, 'counsellors.user', 'counsellors.avatarFile'],
                ]);
            },
        ];

        $therapySessions = Session::query()
            ->with($with)
            ->where('for_type', Therapy::class)
            ->whereIn('for_id', $counsellor->therapies()->pluck('therapies.id'))
            ->whereWithinRange($startDate, $endDate)
            ->get();

        $groupTherapyIds = GroupTherapy::query()
            ->where(function ($query) use ($counsellor) {
                $query
                    ->where('addedby_type', Counsellor::class)
                    ->where('addedby_id', $counsellor->id);
            })
            ->orWhereHas('counsellors', function ($query) use ($counsellor) {
                // wherePivot() doesn't work inside a whereHas() closure -- it isn't aware of the
                // pivot join in that context and produces a literal (and invalid) `pivot = ...`
                // clause. Reference the actual pivot table/column instead.
                $query
                    ->whereKey($counsellor->id)
                    ->where('counsellor_group_therapy.state', CounsellorGroupTherapyStateEnum::active->value);
            })
            ->pluck('id');

        $groupTherapySessions = Session::query()
            ->with($with)
            ->where('for_type', GroupTherapy::class)
            ->whereIn('for_id', $groupTherapyIds)
            ->whereWithinRange($startDate, $endDate)
            ->get();

        return $therapySessions
            ->merge($groupTherapySessions)
            ->sortBy('start_time')
            ->values();
    }
}
