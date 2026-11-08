<?php

namespace App\Contracts;

use App\Support\NotificationEntry;

// SCRUM-39: a notification the in-app notification center can list. present() builds what the list shows
// from the notification's saved data (its toArray()) and fixed text only -- never a live look-up, never
// more than its email says, and never an internal id or a person reference in the text (ids may only build
// the link). One place per class for its wording, next to the data it reads.
interface ShownInNotificationCenter
{
    public static function present(array $data): NotificationEntry;
}
