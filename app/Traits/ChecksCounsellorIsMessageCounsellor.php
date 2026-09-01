<?php

namespace App\Traits;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Message;

// Shared by MessageNote's Ensure*Actions (SCRUM-202/TT-2.3a). A message note is a counsellor-only
// clinical annotation, so authorization checks "is this counsellor THE counsellor for the message's
// context" -- not merely "is this user any kind of participant" (which, for a Session, would also
// admit the client). Message::for is polymorphic over Session (whose own `for` is a Therapy/
// GroupTherapy carrying the actual isCounsellor() check) and Discussion (counsellor-only
// participants directly) -- mirrors the branch shape already established by
// EnsureCanDeleteMessageForSelfAction/EnsureCanSendMessageToForAction for the analogous
// participant check on Message itself.
trait ChecksCounsellorIsMessageCounsellor
{
    protected function counsellorIsMessageCounsellor(?Message $message, ?Counsellor $counsellor): bool
    {
        if (! $message || ! $counsellor) {
            return false;
        }

        $for = $message->for;

        if (! $for) {
            return false;
        }

        if ($for::class === Discussion::class) {
            return (bool) $for->isParticipant($counsellor);
        }

        return (bool) $for->for?->isCounsellor($counsellor);
    }
}
