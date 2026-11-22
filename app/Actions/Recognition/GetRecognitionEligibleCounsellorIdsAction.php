<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Models\Counsellor;

// TT-7.11d1/SCRUM-473: which of $ids may be recognised now -- currently verified (not lapsed or revoked), not
// suspended, not deleted (nor their account), and not the reported party of an open or under-review report
// (GetCounsellorIdsWithOpenReportsAction; pass $reportedIds when already worked out). Always as of now, whichever
// month is being decided.
class GetRecognitionEligibleCounsellorIdsAction extends Action
{
    /**
     * @param  array<int, int>  $ids
     * @param  array<int, int>|null  $reportedIds
     * @return array<int, int>
     */
    public function execute(array $ids, ?array $reportedIds = null): array
    {
        if ($ids === []) {
            return [];
        }

        $reportedIds ??= GetCounsellorIdsWithOpenReportsAction::new()->execute($ids)['counsellorIds'];

        // Soft-deleted counsellors are left out by the default scope; the user relation is withTrashed, so a deleted
        // account is excluded here.
        return Counsellor::query()
            ->whereIn('id', $ids)
            ->whereCurrentlyVerified()
            ->whereNotSuspended()
            ->whereHas('user', fn ($query) => $query->whereNull('users.deleted_at'))
            ->when($reportedIds !== [], fn ($query) => $query->whereNotIn('id', $reportedIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
