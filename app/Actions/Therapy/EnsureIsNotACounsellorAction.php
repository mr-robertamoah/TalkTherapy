<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\AssistTherapyDTO;
use App\Exceptions\TherapyException;
use Illuminate\Database\Eloquent\Collection;

class EnsureIsNotACounsellorAction extends Action
{
    public function execute(AssistTherapyDTO $assistTherapyDTO)
    {
        $counsellorId = $assistTherapyDTO->user->counsellor?->id;
        $counsellors = array_filter(
            $assistTherapyDTO->counsellors::class == Collection::class
                ? $assistTherapyDTO->counsellors->toArray()
                : $assistTherapyDTO->counsellors, 
            fn ($counsellor) => $counsellorId == $counsellor['id']);

        if (!count($counsellors)) return;

        throw new TherapyException("You cannot send an assistance request to your counsellor account.", 422);
    }
}