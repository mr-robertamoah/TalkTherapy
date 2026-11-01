<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\Enums\ReportStatusEnum;
use App\Exceptions\ReportException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-340: an admin names who a report is about -- the only way a group report gets a reported
// party (reporters never name group members by id, SCRUM-347), and how an admin corrects one. The
// person must be a participant of the reported therapy and not the reporter. Only while the report
// is still open or under review, since it decides whose confidential messages can be unlocked.
class RecordReportedPartyAction extends Action
{
    public function execute(User $admin, ?Report $report, string $type, int $id): Report
    {
        EnsureAdminCanReviewReportAction::new()->execute($admin, $report);

        $therapy = $report->therapy();
        $person = match ($type) {
            'user' => User::find($id),
            'counsellor' => Counsellor::find($id),
            default => null,
        };

        if (! $therapy || ! $person || ! $this->isPartOf($therapy, $person)) {
            throw new ReportException(EnsureReportDataIsValidAction::NOT_A_PARTICIPANT, 422);
        }

        $personUser = $person instanceof Counsellor ? $person->user : $person;
        if ($report->isReportedBy($personUser)) {
            throw new ReportException('The reporter cannot be the reported person.', 422);
        }

        return DB::transaction(function () use ($admin, $report, $type, $id) {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);

            // Checked on the locked row, so a concurrent close can't slip past it.
            if (! $report->isActive()) {
                throw new ReportException('Reopen the report before changing who it is about.', 422);
            }

            $before = array_intersect_key($report->data ?: [], array_flip(['userId', 'counsellorId']));
            $after = $type == 'user' ? ['userId' => $id] : ['counsellorId' => $id];

            $report->forceFill(['data' => $after])->save();

            ReportStatusHistory::create([
                'report_id' => $report->id,
                'user_id' => $admin->id,
                'event' => ReportStatusHistory::EVENT_PARTY_RECORDED,
                'data' => ['from' => $before ?: null, 'to' => $after],
            ]);

            // Security review: naming the party starts the review, so the reporter can no longer
            // edit or delete the report (and with it the admin's choice and its history).
            if ($report->status == ReportStatusEnum::open) {
                TransitionReportStatusAction::new()->execute($admin, $report, ReportStatusEnum::under_review);
            }

            return $report->refresh();
        });
    }

    private function isPartOf(Therapy|GroupTherapy $therapy, User|Counsellor $person): bool
    {
        if ($person instanceof Counsellor) {
            return (bool) $therapy->isCounsellor($person);
        }

        if ($therapy instanceof Therapy) {
            return (bool) $therapy->isUser($person);
        }

        return (bool) $therapy->isParticipant($person);
    }
}
