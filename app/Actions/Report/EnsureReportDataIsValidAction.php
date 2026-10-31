<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// Runs after EnsureCanReportReportableAction, so the reporter is already a participant -- these
// checks can't be used to probe who is in a therapy (SCRUM-347).
class EnsureReportDataIsValidAction extends Action
{
    public const NOT_A_PARTICIPANT = 'The person you are reporting is not part of this therapy.';

    public const INVALID_DATA = 'The report details are not valid.';

    public function execute(CreateReportDTO $createReportDTO, string $action = 'create')
    {
        $this->validatePeople($createReportDTO, $action);

        if (
            $this->canCreate($createReportDTO) ||
            ($action == 'update' && $this->canUpdate($createReportDTO))
        ) {
            return;
        }

        throw new ReportException("You have not provided enough data to {$action} report.", 422);
    }

    private function canCreate(CreateReportDTO $createReportDTO)
    {
        return $createReportDTO->description && $createReportDTO->reportable;
    }

    // Whoever `data` names must be part of the reported therapy. On update, that's the merged data
    // UpdateReportAction will store, checked against the report's effective reportable -- otherwise
    // an update could name anyone, or keep a stale person after a re-point (security review).
    private function validatePeople(CreateReportDTO $createReportDTO, string $action): void
    {
        // A re-pointed report's people are replaced, not merged (UpdateReportAction::setData()).
        $data = $action == 'update' && ! $createReportDTO->reportable
            ? array_merge($createReportDTO->report?->data ?: [], $createReportDTO->data ?: [])
            : ($createReportDTO->data ?: []);
        $reportable = $createReportDTO->reportable ?? ($action == 'update' ? $createReportDTO->report?->reportable : null);

        // Only these two keys, as ids -- anything else was stored and returned verbatim.
        foreach ($data as $key => $value) {
            if (! in_array($key, ['userId', 'counsellorId'], true) || ! (is_null($value) || is_int($value) || ctype_digit((string) $value))) {
                throw new ReportException(self::INVALID_DATA, 422);
            }
        }

        $userId = $data['userId'] ?? null;
        $counsellorId = $data['counsellorId'] ?? null;

        // On update, the same rule DefaultReportedPartyAction applies on create: never accept a
        // client-sent id for a client who is anonymous to the reporter (no guess oracle).
        $therapyOfReport = $reportable instanceof Session ? $reportable->for : $reportable;
        if (
            $action == 'update' && ($createReportDTO->data['userId'] ?? null) &&
            $therapyOfReport instanceof Therapy && $therapyOfReport->addedByUserIsMaskedFor($createReportDTO->user)
        ) {
            throw new ReportException(self::NOT_A_PARTICIPANT, 422);
        }

        $reporter = $createReportDTO->user;
        if (($userId && $userId == $reporter?->id) || ($counsellorId && $counsellorId == $reporter?->counsellor?->id)) {
            throw new ReportException('You cannot report yourself.', 422);
        }

        // A session's people are its therapy's people.
        $therapy = $reportable instanceof Session ? $reportable->for : $reportable;

        if (! $therapy instanceof Therapy && ! $therapy instanceof GroupTherapy) {
            if ($userId || $counsellorId) {
                throw new ReportException(self::INVALID_DATA, 422);
            }

            return;
        }

        // An individual therapy report names at least one person (SCRUM-347: this used to demand
        // BOTH, so reporting just the client or just the counsellor always failed).
        // Reached when the other party doesn't exist yet (a client reporting a therapy with no
        // counsellor) -- DefaultReportedPartyAction fills in everyone else.
        if ($reportable instanceof Therapy && ! $userId && ! $counsellorId) {
            throw new ReportException('Please choose who you are reporting in this therapy.', 422);
        }

        // Group members are never named by id: whether an id belongs to the group would answer
        // "who is in this group", anonymous members included (security review). A member is
        // reported through the group as a whole, named in the description.
        if ($userId && ! ($therapy instanceof Therapy && $therapy->isUser(User::find($userId) ?? new User))) {
            throw new ReportException(self::NOT_A_PARTICIPANT, 422);
        }

        $counsellor = $counsellorId ? Counsellor::find($counsellorId) : null;
        if ($counsellorId && ! ($counsellor && $therapy->isCounsellor($counsellor))) {
            throw new ReportException(self::NOT_A_PARTICIPANT, 422);
        }
    }

    private function canUpdate(CreateReportDTO $createReportDTO)
    {
        return $createReportDTO->description ||
            $createReportDTO->files ||
            $createReportDTO->deletedFiles ||
            $createReportDTO->reportable;
    }
}
