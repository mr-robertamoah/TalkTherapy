<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Models\User;
use App\Notifications\GuardianSafetyNoticeNotification;
use App\Notifications\SafetyNoticeNotification;

// SCRUM-32 (TT-4.3c): the persistent notice a person sees until they dismiss it -- the neutral one about
// themselves, or (as a guardian) one asking them to check in with their ward. A fixed projection: the
// message and, for a guardian, the ward's first name; never the stored notification row (its WardRef
// stays server-side) and nothing about any flag. There is no notification list yet (SCRUM-395 item 9).
class GetSafetyNoticeAction extends Action
{
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
        return $id !== null && $this->unread($user)->whereKey($id)->update(['read_at' => now()]) > 0;
    }

    // Through the user's guarded notifications (App\Models\DatabaseNotification -- reviews). "Dismissed" is
    // read_at: SCRUM-395's list must never mark these two read on its own (decision log).
    private function unread(User $user)
    {
        return $user->unreadNotifications()->whereIn('type', [SafetyNoticeNotification::class, GuardianSafetyNoticeNotification::class]);
    }
}
