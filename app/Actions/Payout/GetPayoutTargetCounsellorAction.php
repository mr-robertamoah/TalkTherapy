<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\DTOs\TriggerPayoutDTO;
use App\Exceptions\PayoutException;
use App\Models\Counsellor;

// TT-7.6c/SCRUM-227: resolves WHO a payout trigger is actually for -- an admin's counsellorId
// input is only ever honored when the initiator is genuinely an admin (self-triggering a payout
// "for yourself" via this field, as a plain counsellor, is not a shortcut around anything since a
// counsellor would resolve to their own payout via the no-counsellorId path anyway, but is also
// not specially trusted -- only an admin's explicit target selection is).
class GetPayoutTargetCounsellorAction extends Action
{
    public function execute(TriggerPayoutDTO $dto): Counsellor
    {
        if ($dto->user?->isAdmin() && $dto->counsellorId) {
            $counsellor = Counsellor::find($dto->counsellorId);

            if (! $counsellor) {
                throw new PayoutException('Counsellor not found.', 404);
            }

            return $counsellor;
        }

        if ($dto->user?->counsellor) {
            return $dto->user->counsellor;
        }

        throw new PayoutException('Only a counsellor (for their own payout) or an admin (on a counsellor\'s behalf) can trigger a payout.', 422);
    }
}
