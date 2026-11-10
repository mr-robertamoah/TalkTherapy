<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\Enums\ReportOutcomeEnum;
use App\Enums\ReportStatusEnum;
use App\Exceptions\ReportException;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\User;
use App\Notifications\ReportClosedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-340: moves a report through its review lifecycle (ReportStatusEnum::allowedNext()) and
// records each step. Resolving needs an outcome and a note; dismissing needs a note. Closing a
// report tells the reporter and the reported person (never which admin reviewed it).
class TransitionReportStatusAction extends Action
{
    public function execute(
        User $admin,
        ?Report $report,
        ReportStatusEnum $to,
        ?ReportOutcomeEnum $outcome = null,
        ?string $note = null,
    ): Report {
        EnsureAdminCanReviewReportAction::new()->execute($admin, $report);

        $note = trim((string) $note) ?: null;

        if ($to == ReportStatusEnum::resolved && (! $outcome || ! $note)) {
            throw new ReportException('Resolving a report needs an outcome and a note.', 422);
        }

        if ($to == ReportStatusEnum::dismissed && ! $note) {
            throw new ReportException('Dismissing a report needs a note saying why.', 422);
        }

        $report = DB::transaction(function () use ($admin, $report, $to, $outcome, $note) {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            $from = $report->status;

            if (! in_array($to, $from->allowedNext(), true)) {
                throw new ReportException("A report that is {$this->label($from)} can't be moved to {$this->label($to)}.", 422);
            }

            $closing = ! $to->isActive();

            $report->forceFill([
                'status' => $to,
                'outcome' => $to == ReportStatusEnum::resolved ? $outcome : null,
                'resolution_note' => $closing ? $note : null,
                'resolved_at' => $closing ? now() : null,
                'resolved_by' => $closing ? $admin->id : null,
            ])->save();

            ReportStatusHistory::create([
                'report_id' => $report->id,
                'user_id' => $admin->id,
                'event' => ReportStatusHistory::EVENT_STATUS_CHANGE,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'outcome' => $to == ReportStatusEnum::resolved ? $outcome?->value : null,
                'note' => $note,
            ]);

            return $report;
        });

        if (! $to->isActive()) {
            $this->notifyParties($report);
        }

        return $report;
    }

    private function notifyParties(Report $report): void
    {
        $reporter = $report->reporterUser();
        $confidentialRead = $report->hasConfidentialReads();

        if ($reporter) {
            Notification::send($reporter, new ReportClosedNotification($report, $report->status, ReportClosedNotification::REPORTER, $confidentialRead));
        }

        $reported = $report->reportedUser();
        // SCRUM-398 (the user's decision): never to someone who asked for urgent help -- being told "a report
        // involving you" after reaching out in a crisis can harm someone reported wrongly.
        if ($reported && ! $reported->is($reporter) && ! $report->masksReportedIdentity()) {
            Notification::send($reported, new ReportClosedNotification($report, $report->status, ReportClosedNotification::REPORTED, $confidentialRead));
        }
    }

    private function label(ReportStatusEnum $status): string
    {
        return str_replace('_', ' ', $status->value);
    }
}
