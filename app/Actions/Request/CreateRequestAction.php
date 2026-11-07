<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;

class CreateRequestAction extends Action
{
    private const NEW_WORK_TYPES = [
        RequestTypeEnum::therapy->value,
        RequestTypeEnum::groupTherapy->value,
        RequestTypeEnum::discussion->value,
    ];

    public function execute(CreateRequestDTO $createRequestDTO)
    {
        // SCRUM-359: every request is created here -- so this is where a suspended counsellor is
        // kept from offering to work with someone, and from being asked to.
        if (in_array($createRequestDTO->type, RequestTypeEnum::counsellingTypes(), true)) {
            EnsureCanActAsCounsellorAction::new()->execute($createRequestDTO->from);
            EnsureCanActAsCounsellorAction::new()->execute($createRequestDTO->to, asTarget: true);
        }

        // SCRUM-389: a backstop for new work only (taking on a therapy or a group, a discussion) -- the
        // checks where each request is made stay the main ones (they run before a therapy row exists).
        if (in_array($createRequestDTO->type, self::NEW_WORK_TYPES, true)) {
            $forDiscussion = $createRequestDTO->type === RequestTypeEnum::discussion->value;
            EnsureCounsellorMayTakeOnClientsAction::new()->execute($createRequestDTO->from instanceof Counsellor ? $createRequestDTO->from : null, forDiscussion: $forDiscussion);
            EnsureCounsellorMayTakeOnClientsAction::new()->execute($createRequestDTO->to instanceof Counsellor ? $createRequestDTO->to : null, asTarget: true, forDiscussion: $forDiscussion);
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
