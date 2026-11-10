<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\ReportUnlockScope;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Report;
use App\Models\Session;
use App\Models\Therapy;

// SCRUM-340: the one place that decides what a report unlocks. Nothing unless the report is still
// open or under review AND names exactly one reported person (Report::reportedParty()). Then: a
// reported session, or every session of a reported therapy/group -- and within those, only messages
// the reported person sent or received (ReportUnlockScope::reveals). Posts and discussions unlock
// nothing. Callers resolve it from the report row they hold the lock on.
class ResolveReportUnlockScopeAction extends Action
{
    public function execute(Report $report): ReportUnlockScope
    {
        $party = $report->isActive() ? $report->reportedParty() : null;

        if (! $party) {
            return ReportUnlockScope::none();
        }

        $reportable = $report->reportable;
        $sessionIds = match (true) {
            $reportable instanceof Session && ($reportable->for instanceof Therapy || $reportable->for instanceof GroupTherapy) => collect([$reportable->id]),
            $reportable instanceof Therapy, $reportable instanceof GroupTherapy => $reportable->sessions()->pluck('id'),
            // SCRUM-398: an urgent help request's chat, while its messages are kept.
            $reportable instanceof HelpRequest => collect($reportable->session_id && ! $reportable->transcript_deleted_at ? [$reportable->session_id] : []),
            default => collect(),
        };

        // A person writes as their User and (if they're a counsellor) as their Counsellor.
        $user = $party instanceof Counsellor ? $party->user : $party;
        $counsellor = $party instanceof Counsellor ? $party : $party->counsellor;

        return new ReportUnlockScope(
            $sessionIds,
            array_values(array_filter([$user?->id])),
            array_values(array_filter([$counsellor?->id])),
        );
    }
}
