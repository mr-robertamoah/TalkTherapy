<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Enums\ReportStatusEnum;
use App\Models\Counsellor;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// TT-7.11d1/SCRUM-473: which of $ids are the reported party of an open or under-review report -- such a counsellor
// isn't suggested or recognised (the user's decision). Who a report is about is Report::reportedParty(), the one
// answer the admin report pages use: a Counsellor directly, or a User whose counsellor profile it is (an urgent help
// request is about the User who asked). One pass over the active reports, in chunks.
//
// A report whose party is ambiguous or unnamed (reportedParty() null) counts against nobody -- an admin records who it
// is about first. How many such reports there are is returned as `unattributed`, for the admin page (UNATTRIBUTED_NOTE).
//
// TT-7.11d2/SCRUM-474 (security review M1): a report about an urgent help request hides who asked for help
// (Report::masksReportedIdentity(); revealed only through the logged RevealReportedHelpRequesterAction). Its party
// still counts in `counsellorIds` (they aren't eligible), but not in `flaggableCounsellorIds` -- the ones the admin
// page may mark "open report" -- and it is added to `unattributed` instead, whether or not the person who asked is a
// counsellor, so the count doesn't say that either.
class GetCounsellorIdsWithOpenReportsAction extends Action
{
    public const UNATTRIBUTED_NOTE = 'Open reports that do not name exactly one person are not counted against anyone until an admin records who they are about. Reports about an urgent help request are included in this number too: they keep the person concerned out of the suggestions, but who that is stays private here (reveal it from the report).';

    private const CHUNK = 200;

    /**
     * @param  array<int, int>  $ids
     * @return array{counsellorIds: array<int, int>, flaggableCounsellorIds: array<int, int>, unattributed: int}
     */
    public function execute(array $ids): array
    {
        if ($ids === []) {
            return ['counsellorIds' => [], 'flaggableCounsellorIds' => [], 'unattributed' => 0];
        }

        // Counsellor ids and user ids named, each with whether the report may be shown against them.
        $counsellorIds = [];
        $userIds = [];
        $masked = ['counsellor' => [], 'user' => []];
        $unattributed = 0;

        Report::query()
            ->whereIn('status', [ReportStatusEnum::open->value, ReportStatusEnum::under_review->value])
            // What reportedParty() reads: the reportable (a help request), and the reporter with a reporting
            // counsellor's user (isReportedBy()); the people named are read per chunk by preloadReportedParties().
            ->with(['reportable', 'addedby' => fn (MorphTo $morph) => $morph->morphWith([Counsellor::class => ['user']])])
            ->chunkById(self::CHUNK, function ($reports) use (&$counsellorIds, &$userIds, &$masked, &$unattributed) {
                Report::preloadReportedParties($reports);

                foreach ($reports as $report) {
                    $party = $report->reportedParty();
                    $hidden = $report->masksReportedIdentity();

                    if ($hidden || ! $party) {
                        $unattributed++;
                    }

                    if ($party instanceof Counsellor) {
                        $hidden ? $masked['counsellor'][] = $party->id : $counsellorIds[] = $party->id;
                    } elseif ($party instanceof User) {
                        $hidden ? $masked['user'][] = $party->id : $userIds[] = $party->id;
                    }
                }
            });

        $wanted = array_unique(array_map('intval', $ids));
        $flaggable = array_merge($counsellorIds, $this->profileIds($userIds));
        $all = array_merge($flaggable, $masked['counsellor'], $this->profileIds($masked['user']));

        return [
            'counsellorIds' => array_values(array_intersect($wanted, $all)),
            'flaggableCounsellorIds' => array_values(array_intersect($wanted, $flaggable)),
            'unattributed' => $unattributed,
        ];
    }

    // The counsellor profiles of these users (a deleted profile included).
    private function profileIds(array $userIds): array
    {
        return $userIds === [] ? [] : Counsellor::withTrashed()
            ->whereIn('user_id', array_unique($userIds))
            ->pluck('id')
            ->all();
    }
}
