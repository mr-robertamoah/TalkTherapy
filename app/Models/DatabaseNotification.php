<?php

namespace App\Models;

use App\Support\WardRef;
use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

// SCRUM-387 (security review): a stored notice, as every Notifiable model's notifications() returns
// it (HasGuardedDatabaseNotifications). The WardRef it may carry -- in its data and as the generated
// notifications.ward_ref column (SCRUM-386) -- is only for finding the rows that name someone whose
// account is being anonymised; it never leaves the server, so it is left out of any serialisation.
class DatabaseNotification extends BaseDatabaseNotification
{
    protected $hidden = ['ward_ref'];

    public function toArray()
    {
        $array = parent::toArray();

        if (is_array($array['data'] ?? null)) {
            unset($array['data'][WardRef::KEY]);
        }

        return $array;
    }
}
