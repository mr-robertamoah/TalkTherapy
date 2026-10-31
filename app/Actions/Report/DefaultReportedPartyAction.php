<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\DTOs\CreateReportDTO;
use App\Exceptions\ReportException;
use App\Models\Therapy;

// SCRUM-348: an individual therapy has exactly two parties, so a report on it that names nobody is
// about the other one -- the server fills them in. This is how a counsellor reports an anonymous
// client: TherapyResource no longer sends the client's real id. Runs after
// EnsureCanReportReportableAction, so the reporter is one of the two parties.
class DefaultReportedPartyAction extends Action
{
    public function execute(CreateReportDTO $createReportDTO): void
    {
        $therapy = $createReportDTO->reportable;
        $data = $createReportDTO->data ?: [];

        if (! $therapy instanceof Therapy) {
            return;
        }

        // Security review: when the client is anonymous to the reporter, any client-sent userId is
        // refused, right or wrong -- otherwise success vs "not part of this therapy" confirms a
        // guessed id. The server default below is the only way to name them.
        if (($data['userId'] ?? null) && $therapy->addedByUserIsMaskedFor($createReportDTO->user)) {
            throw new ReportException(EnsureReportDataIsValidAction::NOT_A_PARTICIPANT, 422);
        }

        if (($data['userId'] ?? null) || ($data['counsellorId'] ?? null)) {
            return;
        }

        $createReportDTO->data = $therapy->addedby?->is($createReportDTO->user)
            ? [...$data, 'counsellorId' => $therapy->counsellor_id]
            : [...$data, 'userId' => $therapy->addedby_id];
    }
}
