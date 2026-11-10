<?php

namespace App\Listeners;

use App\Events\NotificationsChangedEvent;
use App\Models\Counsellor;
use App\Models\User;
use App\Support\BestEffortBroadcast;
use Illuminate\Notifications\Events\NotificationSent;

// SCRUM-39: a notification was stored for someone (or their counsellor profile) -- their open pages are
// told, so the bell's count updates live. Best effort: a broadcasting failure never fails the notification.
// (Discovered automatically from app/Listeners -- not registered by hand too, or it would run twice.)
class BroadcastNotificationsChanged
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $userId = match (true) {
            $event->notifiable instanceof User => $event->notifiable->id,
            $event->notifiable instanceof Counsellor => $event->notifiable->user_id,
            default => null,
        };

        if ($userId) {
            BestEffortBroadcast::send(new NotificationsChangedEvent((int) $userId));
        }
    }
}
