<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Actions\Star\CountCounsellorStarsAction;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// TT-7.11d1/SCRUM-473: who to recognise for a month, for the super administrator's page (SCRUM-474).
//
// Counsellors are ranked on new-rule stars earned in [1st of the month, 1st of the next) UTC
// (CountCounsellorStarsAction -- legacy stars never count), among those eligible now
// (GetRecognitionEligibleCounsellorIdsAction): most stars first, then the earliest first star, then the lowest id.
// Ranks are shared by rows tied on both stars and first star (1, 2, 2, 4), and the suggestions are every row ranked
// within config('recognition.per_month') -- the top N plus everyone tied with the Nth.
//
// Each row says whether it was already decided this month (decision, consent), and carries the flags the page shows.
// `others` are rows decided this month that aren't suggested now (added by hand, or since fallen out), with the same
// flags -- e.g. an approved counsellor who has since been suspended.
class GetRecognitionSuggestionsAction extends Action
{
    public function execute(CarbonInterface $month): array
    {
        $from = self::monthStart($month);
        $perMonth = self::perMonth();

        $decisions = CounsellorRecognition::query()->forMonth($from)->get()->keyBy('counsellor_id');
        $ranking = $this->ranking($from, $decisions->keys()->all());

        $suggested = $ranking['ranked']->filter(fn (array $row) => self::isSuggested($row));
        $cutoffRank = $suggested->count() > $perMonth ? $suggested->max('rank') : null;
        $otherIds = $decisions->keys()->diff($suggested->keys())->values();

        // Whether each is suspended, in the same query as their user (an active suspension of any scope covers
        // counselling -- Counsellor::isSuspended()).
        $counsellors = Counsellor::withTrashed()
            ->with(['user' => fn ($query) => $query->withExists(['suspensions as has_active_suspension' => fn ($query) => $query->active()])])
            ->whereIn('id', $suggested->keys()->merge($otherIds)->all())
            ->get()
            ->keyBy('id');

        $row = function ($id) use ($ranking, $decisions, $counsellors, $cutoffRank) {
            $id = (int) $id;
            $ranked = $ranking['ranked']->get($id);

            return $this->row($counsellors->get($id), $id, $ranked, $decisions->get($id), [
                'tiedAtCutoff' => $cutoffRank !== null && ($ranked['rank'] ?? null) === $cutoffRank,
                // Not for a report that hides who asked for help (security review M1): those are in the unattributed count.
                'openReport' => in_array($id, $ranking['flaggableReportedIds'], true),
            ]);
        };

        return [
            'month' => $from->format('Y-m'),
            'perMonth' => $perMonth,
            'approvedCount' => $decisions->filter(fn (CounsellorRecognition $recognition) => $recognition->isApproved())->count(),
            'suggestions' => $suggested->keys()->map($row)->values()->all(),
            'others' => $otherIds->map($row)->values()->all(),
            'unattributedOpenReports' => $ranking['unattributed'],
            'unattributedOpenReportsNote' => GetCounsellorIdsWithOpenReportsAction::UNATTRIBUTED_NOTE,
        ];
    }

    // Every eligible counsellor with a star that month, ranked, keyed by counsellor id: ['stars', 'firstStarAt',
    // 'rank']. $alsoCheckIds: counsellors whose open-report flag is also wanted (one pass over the reports).
    // `reportedIds` is every open-report party (for eligibility); `flaggableReportedIds` leaves out those named only by
    // a report that hides who asked for help (for what the page shows).
    public function ranking(CarbonInterface $month, array $alsoCheckIds = []): array
    {
        $from = self::monthStart($month);
        $counts = CountCounsellorStarsAction::new()->execute(null, $from, $from->addMonth());

        // Every open report is read here: the admin page's unattributed count is about all of them.
        $reports = GetCounsellorIdsWithOpenReportsAction::new()->execute(
            array_values(array_unique(array_merge($counts->keys()->all(), $alsoCheckIds))),
            countUnattributed: true,
        );
        $eligible = GetRecognitionEligibleCounsellorIdsAction::new()->execute($counts->keys()->all(), $reports['counsellorIds']);

        return [
            'ranked' => self::rank($counts->only($eligible)),
            'reportedIds' => $reports['counsellorIds'],
            'flaggableReportedIds' => $reports['flaggableCounsellorIds'],
            'unattributed' => $reports['unattributed'],
        ];
    }

    // Stars desc, first star asc, id asc; a row tied with the one before on both stars and first star shares its rank.
    public static function rank(Collection $counts): Collection
    {
        $sorted = $counts
            ->map(fn (array $count, int $id) => ['id' => $id, 'stars' => $count['stars'], 'firstStarAt' => $count['first_star_at']])
            ->sort(fn (array $a, array $b) => [$b['stars'], $a['firstStarAt']->getTimestamp(), $a['id']]
                <=> [$a['stars'], $b['firstStarAt']->getTimestamp(), $b['id']])
            ->values();

        $ranked = collect();
        $previous = null;

        foreach ($sorted as $position => $row) {
            $tied = $previous && $previous['stars'] === $row['stars']
                && $previous['firstStarAt']->getTimestamp() === $row['firstStarAt']->getTimestamp();
            $row['rank'] = $tied ? $previous['rank'] : $position + 1;
            $ranked->put($row['id'], $row);
            $previous = $row;
        }

        return $ranked;
    }

    // THE rule for "suggested": ranked within the month's cap -- the top N plus everyone tied with the Nth. $row is a
    // ranking() row, or null for a counsellor not ranked (no stars that month, or not eligible).
    public static function isSuggested(?array $row): bool
    {
        return $row !== null && $row['rank'] <= self::perMonth();
    }

    public static function monthStart(CarbonInterface $month): CarbonImmutable
    {
        return CarbonImmutable::create($month->year, $month->month, 1, 0, 0, 0, 'UTC');
    }

    public static function perMonth(): int
    {
        return max(0, (int) config('recognition.per_month'));
    }

    private function row(?Counsellor $counsellor, int $id, ?array $ranked, ?CounsellorRecognition $recognition, array $extra): array
    {
        return [
            'counsellorId' => $id,
            'name' => $counsellor?->getName(),
            'stars' => $ranked['stars'] ?? 0,
            'firstStarAt' => isset($ranked['firstStarAt']) ? $ranked['firstStarAt']->toIso8601ZuluString() : null,
            'rank' => $ranked['rank'] ?? null,
            'tiedAtCutoff' => $extra['tiedAtCutoff'],
            'recognitionId' => $recognition?->id,
            'decision' => $recognition?->decision?->value,
            'consent' => $recognition?->consent?->value,
            'addedManually' => (bool) $recognition?->added_manually,
            // Why a decided row may no longer be eligible (a suggested one always is).
            'flags' => [
                'verificationState' => $counsellor?->verificationState()->value,
                'suspended' => (bool) $counsellor?->user?->has_active_suspension,
                'openReport' => $extra['openReport'],
                'deleted' => ! $counsellor || $counsellor->trashed() || (bool) $counsellor->user?->trashed(),
            ],
        ];
    }
}
