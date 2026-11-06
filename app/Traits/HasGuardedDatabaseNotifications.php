<?php

namespace App\Traits;

use App\Models\DatabaseNotification;
use Illuminate\Database\Eloquent\Relations\MorphMany;

// SCRUM-387 (security review): Notifiable's notifications(), as App\Models\DatabaseNotification,
// which never serialises the notice's WardRef. Used with Notifiable, after it.
trait HasGuardedDatabaseNotifications
{
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }
}
