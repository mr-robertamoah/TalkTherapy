<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Enums\ReportStatusEnum;
use App\Exceptions\ReportException;
use App\Models\Counsellor;
use App\Models\Report;

// TT-7.11f/SCRUM-476 (security M2/L3): one OPEN (or under-review) report at a time per person, reportable and person
// named -- the reporter as themselves or as their counsellor profile; the person named is data.counsellorId /
// data.userId as stored (after DefaultReportedPartyAction), or nobody. An open report about a counsellor hides their
// recognition, so piling up reports would only add noise (and load) without telling the team anything new. Reporting
// a group in general and then one of its counsellors are different reports, so both are allowed. Once a report is
// closed they may report the same again. Runs after the data is validated, so a bad report gets that error first.
class EnsureNoOpenReportByUserAction extends Action
{
    public const ALREADY_OPEN = 'You have already reported this, and that report is still open.';

    public function execute(CreateReportDTO $createReportDTO): void
    {
        $user = $createReportDTO->user;
        $reportable = $createReportDTO->reportable;

        if (! $user || ! $reportable) {
            return;
        }

        $counsellorId = $user->counsellor()->withTrashed()->value('id');

        $open = Report::query()
            ->whereIn('status', [ReportStatusEnum::open->value, ReportStatusEnum::under_review->value])
            ->where('reportable_type', $reportable->getMorphClass())
            ->where('reportable_id', $reportable->getKey())
            ->where(fn ($query) => $this->naming($query, 'counsellorId', $createReportDTO->data['counsellorId'] ?? null))
            ->where(fn ($query) => $this->naming($query, 'userId', $createReportDTO->data['userId'] ?? null))
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('addedby_type', $user->getMorphClass())->where('addedby_id', $user->id))
                ->when($counsellorId, fn ($query) => $query->orWhere(fn ($query) => $query
                    ->where('addedby_type', (new Counsellor)->getMorphClass())
                    ->where('addedby_id', $counsellorId))))
            ->exists();

        if ($open) {
            throw new ReportException(self::ALREADY_OPEN, 422);
        }
    }

    // data.$key is $id (stored as a number or a string), or absent when $id is null.
    private function naming($query, string $key, mixed $id): void
    {
        is_scalar($id) && $id !== ''
            ? $query->whereIn("data->{$key}", [(int) $id, (string) $id])
            : $query->whereNull("data->{$key}");
    }
}
