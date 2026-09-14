<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Enums\RequestTypeEnum;
use App\Models\Request;
use App\Models\User;

// TT-4.11e/SCRUM-306 closeout (reviewer suggestion): extracted so SubmitAgeVerificationAction's
// own idempotency check and ProfileController::show()'s durable pending-indicator check share
// exactly one implementation, rather than two independently-scoped queries (previously
// `whereFor($user)` in one place, `whereFrom($user)` in the other) that only happened to agree
// because ageVerification's `from`/`for` are always the same user -- a future change to that
// invariant could otherwise silently make them diverge.
class GetPendingAgeVerificationRequestAction extends Action
{
    public function execute(User $user): ?Request
    {
        return Request::query()
            ->whereType(RequestTypeEnum::ageVerification->value)
            ->wherePending()
            ->whereFor($user)
            ->first();
    }
}
