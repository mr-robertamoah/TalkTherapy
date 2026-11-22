<?php

namespace App\Actions\Star;

use App\Actions\Action;
use App\Models\Counsellor;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// TT-7.11a/SCRUM-470: how many stars counsellors earned in a window, in one grouped query.
//
// Returns a collection keyed by counsellor id of ['stars' => int, 'first_star_at' => Carbon] -- first_star_at is the
// earliest star in the window (a ranking tie-break). Counsellors with no stars in the window are absent. The window is
// half-open, [from, to); either end may be null (unbounded). $counsellorIds null means every counsellor.
//
//  - New rules (default): only stars on the Counsellor morph that a rule gave (source set) -- what recognition ranks
//    on. config('recognition.stars_count_from'), when set, is an extra floor on the window.
//  - $includeLegacy: what a profile has always shown -- every star on the Counsellor morph plus every star on the
//    counsellor's User morph (Star::scopeWhereStarredCounsellor), legacy or not.
class CountCounsellorStarsAction extends Action
{
    public function execute(
        ?array $counsellorIds,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        bool $includeLegacy = false,
    ): Collection {
        if ($counsellorIds === []) {
            return collect();
        }

        $query = $includeLegacy
            ? $this->legacyQuery($counsellorIds)
            : $this->newRulesQuery($counsellorIds);

        if (! $includeLegacy && ($floor = config('recognition.stars_count_from'))) {
            $floor = Carbon::parse($floor);
            $from = $from && $from->greaterThan($floor) ? $from : $floor;
        }

        $query
            ->when($from, fn ($q) => $q->where('stars.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('stars.created_at', '<', $to));

        return $query->get()->mapWithKeys(fn ($row) => [
            (int) $row->counsellor_id => [
                'stars' => (int) $row->stars,
                'first_star_at' => Carbon::parse($row->first_star_at),
            ],
        ]);
    }

    private function newRulesQuery(?array $counsellorIds): Builder
    {
        return DB::table('stars')
            ->selectRaw('stars.starred_id as counsellor_id, count(*) as stars, min(stars.created_at) as first_star_at')
            ->where('stars.starred_type', Counsellor::class)
            ->whereNotNull('stars.source')
            ->when($counsellorIds !== null, fn ($q) => $q->whereIn('stars.starred_id', $counsellorIds))
            ->groupBy('stars.starred_id');
    }

    // A User-morph star is the counsellor's through counsellors.user_id. The join only matches User-morph rows, so a
    // Counsellor-morph star is never repeated; soft-deleted counsellors are included, as the accessors always did.
    private function legacyQuery(?array $counsellorIds): Builder
    {
        return DB::table('stars')
            ->leftJoin('counsellors as by_user', function ($join) {
                $join->on('by_user.user_id', '=', 'stars.starred_id')
                    ->where('stars.starred_type', '=', User::class);
            })
            ->selectRaw(
                'case when stars.starred_type = ? then stars.starred_id else by_user.id end as counsellor_id, '
                    .'count(*) as stars, min(stars.created_at) as first_star_at',
                [Counsellor::class],
            )
            ->where(function ($q) use ($counsellorIds) {
                $q->where(function ($q) use ($counsellorIds) {
                    $q->where('stars.starred_type', Counsellor::class)
                        ->when($counsellorIds !== null, fn ($q) => $q->whereIn('stars.starred_id', $counsellorIds));
                })->orWhere(function ($q) use ($counsellorIds) {
                    $q->where('stars.starred_type', User::class)
                        ->when(
                            $counsellorIds !== null,
                            fn ($q) => $q->whereIn('by_user.id', $counsellorIds),
                            fn ($q) => $q->whereNotNull('by_user.id'),
                        );
                });
            })
            // By the alias: MySQL's ONLY_FULL_GROUP_BY would not match two separately bound copies of the case.
            ->groupBy('counsellor_id');
    }
}
