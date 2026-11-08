<?php

namespace App\Actions\Notification;

use App\Actions\Action;
use App\Events\NotificationsChangedEvent;
use App\Models\User;
use App\Support\BestEffortBroadcast;
use App\Support\NotificationCenter;

// SCRUM-39: the viewer marks one of their notifications read (also when they open it), or all of them --
// never the person's or a guardian's safety notice, which only its own dialog dismisses (SCRUM-32). One that
// isn't theirs, or is one of those, is the same 404. Their other open pages are told (no content).
class MarkNotificationsReadAction extends Action
{
    public function one(User $viewer, string $id): bool
    {
        $marked = $this->unread($viewer)->whereKey($id)->update(['read_at' => now()]);
        $exists = $marked > 0 || $this->markable($viewer)->whereKey($id)->exists();
        if ($marked) {
            $this->tell($viewer);
        }

        return $exists;
    }

    public function all(User $viewer): int
    {
        $marked = $this->unread($viewer)->update(['read_at' => now()]);
        if ($marked) {
            $this->tell($viewer);
        }

        return $marked;
    }

    private function markable(User $viewer)
    {
        return NotificationCenter::query($viewer)->whereNotIn('type', NotificationCenter::NEVER_MARKED_BY_LIST);
    }

    private function unread(User $viewer)
    {
        return $this->markable($viewer)->whereNull('read_at');
    }

    private function tell(User $viewer): void
    {
        BestEffortBroadcast::send(new NotificationsChangedEvent($viewer->id));
    }
}
