<?php

namespace App\Listeners;

use App\Models\Counsellor;
use App\Models\User;
use App\Support\NotificationCategories;
use Illuminate\Notifications\Events\NotificationSending;

// SCRUM-40 (TT-5.5): stops an email or an in-app notification the person switched off for its category -- one check
// for every notification, so no via() method changes. Decided when it is delivered (queued ones too). `mail` and
// `database`; a broadcast only for the few that pop up on the page (NotificationCategories::POP_UPS), stopped with
// "in the app" -- the others are live page updates, not alerts. A counsellor profile follows its person's choices
// (as the keep-for setting does). Always-on notifications (no category) and on-demand ones are never stopped. Returns
// false to stop that channel, null otherwise -- never true, which would stop the other listeners being asked.
class ApplyNotificationChoices
{
    public function handle(NotificationSending $event): ?bool
    {
        $channel = match (true) {
            in_array($event->channel, ['mail', 'database'], true) => $event->channel,
            $event->channel === 'broadcast' && in_array($event->notification::class, NotificationCategories::POP_UPS, true) => 'database',
            default => null,
        };

        if (! $channel) {
            return null;
        }

        $category = NotificationCategories::for($event->notification);
        $user = match (true) {
            $event->notifiable instanceof User => $event->notifiable,
            $event->notifiable instanceof Counsellor => $event->notifiable->loadMissing('user')->user,
            default => null,
        };

        if (! $category || ! $user) {
            return null;
        }

        return $user->wantsNotification($category, $channel) ? null : false;
    }
}
