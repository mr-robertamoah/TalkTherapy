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
            ->whereHas('sentRequests', function($query) use ($assistTherapyDTO) {
                $query
                    ->wherePending()
                    ->whereFor($assistTherapyDTO->therapy);
            })
            ->whereHas('receivedRequests', function($query) use ($assistTherapyDTO) {
                $query
                    ->wherePending()
                    ->whereFor($assistTherapyDTO->therapy);
            })
            ->get();

        if (
            !$counsellorsArray->count()
        ) return;

        $s = $counsellorsArray->count() == 1 ? '' : 's';
        $counsellorNames = implode(', ', $counsellorsArray->map(fn ($counsellor) => $counsellor->getName())->toArray());

        throw new TherapyException("{$counsellorNames} counsellor{$s} already have pending requests. You cannot send another request when the previous ones are pending.", 422);
    }
}