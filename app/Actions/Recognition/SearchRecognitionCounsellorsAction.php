<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\User;
use Carbon\CarbonInterface;

// TT-7.11d2/SCRUM-474: the "add a counsellor" search on the recognition page -- counsellors whose profile name, or
// whose account's names or username, contain $term, who could be added for $month: recognisable now
// (Counsellor::whereRecognisableNow(), and not the party of an open report), neither suggested nor already decided
// that month (those are on the page already, with their own controls), and never the viewer's own profile (nobody
// decides their own recognition). Only an id, name and username -- every row is eligible, so there is no eligibility
// flag to send. At most LIMIT, by id.
class SearchRecognitionCounsellorsAction extends Action
{
    public const LIMIT = 10;

    // How many name matches are read before the open-report and suggested checks narrow them. A bound on the work per
    // keystroke, not a promise: a search matching more than 50 counsellors that loses most of them to those checks can
    // show fewer than LIMIT, and a more specific name finds the rest.
    private const CANDIDATES = 50;

    public function execute(User $viewer, string $term, CarbonInterface $month): array
    {
        $month = GetRecognitionSuggestionsAction::monthStart($month);

        $candidates = Counsellor::query()
            ->whereName($term)
            ->whereRecognisableNow()
            ->whereNotUser($viewer)
            ->whereNotIn('id', CounsellorRecognition::query()->forMonth($month)->select('counsellor_id'))
            ->with('user')
            ->orderBy('id')
            ->limit(self::CANDIDATES)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        // One pass over the open reports covers both the month's ranking and these candidates.
        $ranking = GetRecognitionSuggestionsAction::new()->ranking($month, $candidates->pluck('id')->map(fn ($id) => (int) $id)->all());
        $excluded = array_merge(
            $ranking['reportedIds'],
            $ranking['ranked']->filter(fn (array $row) => GetRecognitionSuggestionsAction::isSuggested($row))->keys()->all(),
        );

        return $candidates
            ->reject(fn (Counsellor $counsellor) => in_array((int) $counsellor->id, $excluded, true))
            ->take(self::LIMIT)
            ->map(fn (Counsellor $counsellor) => [
                'id' => (int) $counsellor->id,
                'name' => $counsellor->getName(),
                'username' => $counsellor->user->username,
            ])
            ->values()
            ->all();
    }
}
