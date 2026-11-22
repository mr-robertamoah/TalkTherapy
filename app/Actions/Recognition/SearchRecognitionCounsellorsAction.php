<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Models\Counsellor;
use App\Models\User;

// TT-7.11d2/SCRUM-474: the "add a counsellor" search on the recognition page -- counsellors whose profile name, or
// whose account's names or username, contain $term, and who could be recognised now
// (GetRecognitionEligibleCounsellorIdsAction: verified, not suspended or deleted, no open report). Never the viewer's
// own profile (nobody decides their own recognition). Only an id, name and username -- every row is eligible, so
// there is no eligibility flag to send. At most LIMIT, by id.
class SearchRecognitionCounsellorsAction extends Action
{
    public const LIMIT = 10;

    // How many name matches are read before the open-report check narrows them.
    private const CANDIDATES = 50;

    public function execute(User $viewer, string $term): array
    {
        // The cheap filters in SQL; the open-report pass (GetRecognitionEligibleCounsellorIdsAction) on what is left.
        $candidates = Counsellor::query()
            ->whereName($term)
            ->whereCurrentlyVerified()
            ->whereNotSuspended()
            ->where('user_id', '!=', $viewer->id)
            ->whereHas('user', fn ($query) => $query->whereNull('users.deleted_at'))
            ->with('user')
            ->orderBy('id')
            ->limit(self::CANDIDATES)
            ->get();

        $eligible = GetRecognitionEligibleCounsellorIdsAction::new()->execute($candidates->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $candidates
            ->filter(fn (Counsellor $counsellor) => in_array((int) $counsellor->id, $eligible, true))
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
