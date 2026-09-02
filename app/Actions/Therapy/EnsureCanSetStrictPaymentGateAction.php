<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\DTOs\CreateTherapyDTO;
use App\Exceptions\TherapyException;

// SCRUM-219/TT-7.5a security-review finding: strictPaymentGate is meant to be a counsellor-
// controlled setting (SCRUM-215 decision #1), but EnsureCanUpdateTherapyAction alone lets the
// therapy's own addedby (the paying client) update *any* field, including this one -- which would
// let that same client silently disable the strict gate SCRUM-219 exists to enforce. Only the
// assigned counsellor or an admin may change it once the therapy already exists.
//
// The creating client themselves is still the one who sets its initial value at CREATE time
// (skipped here via the is_null($dto->therapy) check) -- this app has no counsellor assigned to a
// Therapy until one accepts an invite/application after creation (TherapyService::createTherapy
// sends a Request, it doesn't set counsellor_id), so requiring "must be the counsellor" at create
// time would make the field unsettable by anyone. Once a counsellor exists, they own this setting.
class EnsureCanSetStrictPaymentGateAction extends Action
{
    public function execute(CreateTherapyDTO $dto): void
    {
        if (is_null($dto->therapy) || is_null($dto->strictPaymentGate)) {
            return;
        }

        // Deliberately NO "value is already unchanged" short-circuit here (security review,
        // SCRUM-221): an earlier version returned early whenever the submitted value matched the
        // therapy's current one, before checking identity at all -- which let ANY authenticated
        // caller "set" an arbitrary therapy's gate to its current value and succeed, both an
        // unauthorized write (UpdateTherapyAction still runs, rewriting payment_data) and a
        // boolean oracle (success vs. 422 reveals the current value of a therapy the caller has
        // no relationship to). Authorization must be checked whenever this field is explicitly
        // provided, whether or not the value is actually changing -- an already-authorized
        // admin/counsellor resubmitting the unchanged value still passes below regardless.
        if (
            $dto->user->isAdmin() ||
            ($dto->user->counsellor && $dto->therapy->counsellor_id && $dto->therapy->isCounsellor($dto->user->counsellor))
        ) {
            return;
        }

        throw new TherapyException('Only the assigned counsellor can change the strict payment gate setting.', 422);
    }
}
