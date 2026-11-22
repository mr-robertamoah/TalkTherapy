<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Enums\ReportStatusEnum;
use App\Models\Counsellor;
use App\Models\Report;
use App\Models\User;

// TT-7.11d1/SCRUM-473: which of $ids are the reported party of an open or under-review report -- such a counsellor
// isn't suggested or recognised (the user's decision). Who a report is about is Report::reportedParty(), the one
// answer the admin report pages use: a Counsellor directly, or a User whose counsellor profile it is (an urgent help
// request is about the User who asked). One pass over the active reports, in chunks.
//
// A report whose party is ambiguous or unnamed (reportedParty() null) counts against nobody -- an admin records who it
// is about first. How many such reports there are is returned as `unattributed`, for the admin page (UNATTRIBUTED_NOTE).
class GetCounsellorIdsWithOpenReportsAction extends Action
{
    public const UNATTRIBUTED_NOTE = 'Open reports that do not name exactly one person are not counted against anyone until an admin records who they are about.';

    private const CHUNK = 200;

    /**
     * @param  array<int, int>  $ids
     * @return array{counsellorIds: array<int, int>, unattributed: int}
     */
    public function execute(array $ids): array
    {
        if ($ids === []) {
            return ['counsellorIds' => [], 'unattributed' => 0];
        }

        $counsellorIds = [];
        $userIds = [];
        $unattributed = 0;

        Report::query()
            ->whereIn('status', [ReportStatusEnum::open->value, ReportStatusEnum::under_review->value])
            ->with(['addedby', 'reportable'])
            ->chunkById(self::CHUNK, function ($reports) use (&$counsellorIds, &$userIds, &$unattributed) {
                foreach ($reports as $report) {
                    $party = $report->reportedParty();

                    match (true) {
                        $party instanceof Counsellor => $counsellorIds[] = $party->id,
                        $party instanceof User => $userIds[] = $party->id,
                        default => $unattributed++,
                    };
                }
            });

        if ($userIds !== []) {
            $counsellorIds = array_merge($counsellorIds, Counsellor::withTrashed()
                ->whereIn('user_id', array_unique($userIds))
                ->pluck('id')
                ->all());
        }

        return [
            'counsellorIds' => array_values(array_intersect(array_unique(array_map('intval', $ids)), $counsellorIds)),
            'unattributed' => $unattributed,
        ];
    }
}
