<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Events\NotificationsChangedEvent;
use App\Models\User;
use App\Notifications\GuardianSafetyNoticeNotification;
use App\Notifications\SafetyNoticeNotification;
use App\Support\BestEffortBroadcast;

// SCRUM-32 (TT-4.3c): the persistent notice a person sees until they dismiss it -- the neutral one about
// themselves, or (as a guardian) one asking them to check in with their ward. A fixed projection: the
// message and, for a guardian, the ward's first name; never the stored notification row (its WardRef
// stays server-side) and nothing about any flag. The notification center (SCRUM-39) never marks these read.
class GetSafetyNoticeAction extends Action
{
    // The two kinds of safety notice (NotificationCenter::NEVER_MARKED_BY_LIST is this list).
    public const TYPES = [SafetyNoticeNotification::class, GuardianSafetyNoticeNotification::class];

    public function current(User $user): ?array
    {
        $notice = $this->unread($user)->latest()->first();
        if (! $notice) {
            return null;
        }

        $guardian = $notice->type === GuardianSafetyNoticeNotification::class;

        return [
            'id' => $notice->id,
            'kind' => $guardian ? 'guardian' : 'client',
            // The wording lives on the notification classes (one place for the legal read; reviews).
            'message' => $guardian ? GuardianSafetyNoticeNotification::message($notice->data ?? []) : SafetyNoticeNotification::message(),
        ];
    }

    // Marks one of their own safety notices read (only those two kinds -- anything else is untouched).
    public function dismiss(User $user, ?string $id): bool
    {
        $dismissed = $id !== null && $this->unread($user)->whereKey($id)->update(['read_at' => now()]) > 0;
        // SCRUM-39 (review): their other open pages' bells update (nothing in the ping).
        if ($dismissed) {
            BestEffortBroadcast::send(new NotificationsChangedEvent($user->id));
        }

        return $dismissed;
    }

    // Through the user's guarded notifications (App\Models\DatabaseNotification -- reviews). "Dismissed" is
    // read_at: SCRUM-395's list must never mark these two read on its own (decision log).
    private function unread(User $user)
    {
        return $user->unreadNotifications()->whereIn('type', self::TYPES);
    }
}
