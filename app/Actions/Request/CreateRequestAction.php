<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;

class CreateRequestAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        // SCRUM-359: every request is created here -- so this is where a suspended counsellor is
        // kept from offering to work with someone, and from being asked to.
        if (in_array($createRequestDTO->type, RequestTypeEnum::counsellingTypes(), true)) {
            EnsureCanActAsCounsellorAction::new()->execute($createRequestDTO->from);
            EnsureCanActAsCounsellorAction::new()->execute($createRequestDTO->to, asTarget: true);
        }

        $request = $createRequestDTO->from->sentRequests()->create([
            'data' => $createRequestDTO->data,
            'type' => $createRequestDTO->type,
            'expires_at' => $createRequestDTO->expiresAt,
            'round' => $createRequestDTO->round,
        ]);

        $request->to()->associate($createRequestDTO->to);

        $request->for()->associate($createRequestDTO->for);

        $request->save();

        return $request->refresh();
    }
}
