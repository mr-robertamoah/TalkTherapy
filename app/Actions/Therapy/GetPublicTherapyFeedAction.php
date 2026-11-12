<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Actions\GroupTherapy\PreloadGroupTherapyListAction;
use App\Enums\PaginationEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use App\Support\PreferenceMatch;
use App\Support\PreferenceRanking;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

// SCRUM-113: public therapies and groups as one list -- each ranked in SQL (PreferenceRanking: matches
// first, then the visit's seeded order), unioned and paged in SQL, then only that page's rows are
// loaded (preloaded, with each card's button for the viewer) and put back in the feed's order. The
// same filters as the Home rows (the models' publicForOutsider scopes).
class GetPublicTherapyFeedAction extends Action
{
    // $needsCounsellor (SCRUM-391, the counsellor dashboard's "therapies you could help"): only a
    // therapy with no counsellor yet and a group with a free counsellor place, neither ended.
    public function execute(?User $viewer, int $seed, ?int $perPage = null, bool $needsCounsellor = false): LengthAwarePaginator
    {
        $perPage ??= PaginationEnum::preferencesPagination->value;
        $match = PreferenceMatch::forTherapiesViewedBy($viewer);

        $therapies = Therapy::query()->publicForOutsider($viewer)
            ->when($needsCounsellor, fn ($query) => $query->whereHasNoCounsellor()->where('status', '!=', TherapyStatusEnum::ended->value));
        $groups = GroupTherapy::query()->publicForOutsider($viewer)
            ->when($needsCounsellor, fn ($query) => $query->whereCanTakeCounsellor());

        $union = $this->ranked($therapies, 'individual', $match, $seed)
            ->unionAll($this->ranked($groups, 'group', $match, $seed));

        $page = DB::query()->fromSub($union, 'feed')
            ->orderByDesc(PreferenceRanking::COLUMN)
            ->orderBy('sort_key')
            ->orderBy('kind')
            ->orderBy('id')
            ->paginate($perPage)
            ->appends(['seed' => $seed]);

        $rows = collect($page->items());
        $models = [
            'individual' => Therapy::query()->whereKey($rows->where('kind', 'individual')->pluck('id'))->get()->keyBy('id'),
            'group' => GroupTherapy::query()->whereKey($rows->where('kind', 'group')->pluck('id'))->get()->keyBy('id'),
        ];
        PreloadTherapyListAction::new()->execute($models['individual']);
        PreloadGroupTherapyListAction::new()->execute($models['group']);
        ResolveTherapyCardStateAction::new()->execute($models['individual']->values()->concat($models['group']->values()), $viewer);
        ResolveTherapySponsorAction::new()->execute($models['individual']->values()->concat($models['group']->values()), $viewer);

        $items = $rows->map(function ($row) use ($models) {
            $model = $models[$row->kind]->get($row->id);
            $model?->setAttribute(PreferenceRanking::COLUMN, (int) $row->{PreferenceRanking::COLUMN});

            return $model;
        })->filter()->values();

        return $page->setCollection($items);
    }

    private function ranked($query, string $kind, PreferenceMatch $match, int $seed)
    {
        $table = $query->getModel()->getTable();
        [$score, $bindings] = $match->isEmpty() ? ['0', []] : PreferenceRanking::score($query->getModel(), $table, $match);

        return $query->toBase()
            ->select("{$table}.id")
            ->selectRaw('? as kind', [$kind])
            ->selectRaw("({$score}) as ".PreferenceRanking::COLUMN, $bindings)
            ->selectRaw(PreferenceRanking::sortKey("{$table}.id", $seed).' as sort_key');
    }
}
