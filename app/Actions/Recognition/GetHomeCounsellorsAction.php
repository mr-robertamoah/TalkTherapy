<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Models\CounsellorRecognition;
use App\Models\User;
use App\Services\CounsellorService;
use Illuminate\Support\Collection;

// TT-7.11f/SCRUM-476: Home's two counsellor rows -- the Counsellors of the month this viewer may see
// (GetVisibleRecognitionsAction) and, for a signed-in viewer only, who leads this month (CounsellorService). The open
// reports that hide someone are read once, for both rows' candidates together (security M2).
class GetHomeCounsellorsAction extends Action
{
    /** @return array{recognised: Collection<int, CounsellorRecognition>, leading: Collection} */
    public function execute(?User $viewer): array
    {
        $visible = GetVisibleRecognitionsAction::new();
        $service = CounsellorService::new();

        $recognitions = $visible->candidates($viewer);
        $leadingIds = $viewer ? $service->getLeadingCandidateIds() : [];

        $hiddenIds = GetVisibleRecognitionsAction::publiclyHiddenIds(
            array_merge($recognitions->pluck('counsellor_id')->all(), $leadingIds)
        );

        return [
            'recognised' => $visible->withoutHidden($recognitions, $hiddenIds),
            'leading' => $viewer ? $service->getLeadingCounsellors($leadingIds, $hiddenIds) : collect(),
        ];
    }
}
