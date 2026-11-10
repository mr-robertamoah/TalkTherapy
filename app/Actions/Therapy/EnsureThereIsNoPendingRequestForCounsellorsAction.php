<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\Exceptions\TherapyException;
use App\Models\Counsellor;
use App\Models\Request;

class EnsureThereIsNoPendingRequestForCounsellorsAction extends Action
{
    public function execute(AssistTherapyDTO $assistTherapyDTO)
    {
        $counsellorIds = $assistTherapyDTO->counsellors->map(fn ($counsellor) => $counsellor->id)->toArray();

        $counsellorsArray = Counsellor::query()
            ->whereIn('id', $counsellorIds)
            // SCRUM-344: a pending request in EITHER direction blocks another -- this used to need
            // both at once, so it almost never fired. Grouped so the OR stays inside whereIn().
            ->where(function ($query) use ($assistTherapyDTO) {
                $query
                    ->whereHas('sentRequests', fn ($q) => $q->wherePending()->whereFor($assistTherapyDTO->therapy))
                    ->orWhereHas('receivedRequests', fn ($q) => $q->wherePending()->whereFor($assistTherapyDTO->therapy));
            })
            ->get();

        if (
            ! $counsellorsArray->count()
        ) {
            return;
        }

        $s = $counsellorsArray->count() == 1 ? '' : 's';
        $counsellorNames = implode(', ', $counsellorsArray->map(fn ($counsellor) => $counsellor->getName())->toArray());

        throw new TherapyException("{$counsellorNames} counsellor{$s} already have pending requests. You cannot send another request when the previous ones are pending.", 422);
    }
}
