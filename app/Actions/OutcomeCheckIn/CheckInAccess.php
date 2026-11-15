<?php

namespace App\Actions\OutcomeCheckIn;

use App\Actions\Safety\EnsureCanFlagClientAction;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\OutcomeCheckInException;
use App\Models\OutcomeCheckIn;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-37 (TT-5.2): who may do what with a therapy's check-ins. Only a 1:1 therapy (never an urgent help chat or a
// group): its counsellor sends, cancels, acknowledges and sees them all; its client answers and sees their own.
// Nobody else -- not a guardian, an organisation or an administrator -- and every refusal is the same 404, so nothing
// says a check-in exists.
class CheckInAccess
{
    public static function isCounsellor(?User $viewer, ?Therapy $therapy): bool
    {
        return EnsureCanFlagClientAction::isTherapyCounsellor($viewer, $therapy);
    }

    public static function isClient(?User $viewer, ?Therapy $therapy): bool
    {
        return $viewer && $therapy && ! $therapy->isHelpChat()
            && $therapy->addedby_type === $viewer->getMorphClass() && (int) $therapy->addedby_id === (int) $viewer->id;
    }

    public static function canSend(?User $viewer, ?Therapy $therapy): bool
    {
        return (bool) config('check_ins.enabled') && self::isCounsellor($viewer, $therapy)
            && $therapy->status !== TherapyStatusEnum::ended->value && $viewer->counsellor?->isVerified();
    }

    // The check-in, for its therapy's counsellor or its client -- else a 404.
    public static function find(?User $viewer, int $checkInId): OutcomeCheckIn
    {
        $checkIn = OutcomeCheckIn::query()->with('therapy')->find($checkInId);

        if (! $checkIn || ! (self::isCounsellor($viewer, $checkIn->therapy) || ((int) $checkIn->client_user_id === (int) $viewer?->id && self::isClient($viewer, $checkIn->therapy)))) {
            throw OutcomeCheckInException::notFound();
        }

        return $checkIn;
    }
}
