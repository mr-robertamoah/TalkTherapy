<?php

namespace App\Actions\OutcomeCheckIn;

use App\Actions\Action;
use App\Exceptions\OutcomeCheckInException;
use App\Models\OutcomeCheckIn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-37 (TT-5.2): the counsellor withdraws a check-in still waiting, or acknowledges one that needs attention.
class ManageCheckInAction extends Action
{
    public function cancel(User $viewer, int $checkInId): OutcomeCheckIn
    {
        $this->forCounsellor($viewer, $checkInId);

        // Under the same lock as answering (code review): an answer sent a moment before is never overwritten.
        return DB::transaction(function () use ($checkInId) {
            $locked = OutcomeCheckIn::query()->lockForUpdate()->findOrFail($checkInId);

            if ($locked->status !== OutcomeCheckIn::REQUESTED) {
                throw new OutcomeCheckInException('Only a check-in still waiting can be withdrawn.', 409);
            }

            $locked->forceFill(['status' => OutcomeCheckIn::CANCELLED, 'cancelled_at' => now(), 'open_key' => null])->save();

            return $locked;
        });
    }

    public function acknowledge(User $viewer, int $checkInId): OutcomeCheckIn
    {
        $checkIn = $this->forCounsellor($viewer, $checkInId);

        if ($checkIn->needs_attention && ! $checkIn->attention_acknowledged_at) {
            $checkIn->forceFill(['attention_acknowledged_at' => now()])->save();
        }

        return $checkIn;
    }

    private function forCounsellor(User $viewer, int $checkInId): OutcomeCheckIn
    {
        $checkIn = CheckInAccess::find($viewer, $checkInId);

        if (! CheckInAccess::isCounsellor($viewer, $checkIn->therapy)) {
            throw OutcomeCheckInException::notFound();
        }

        return $checkIn;
    }
}
