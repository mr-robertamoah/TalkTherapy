<?php

namespace App\Support;

use App\Models\User;

// A person shown by name only -- no dob, gender or country (SCRUM-294/305/387/388): the admin queue,
// a refund's payer, a counsellor on an admin queue row, and anywhere else a viewer has no established
// relationship with the person. One definition for every resource that narrows (SCRUM-389).
final class NarrowUserProjection
{
    public static function of(?User $user): array
    {
        return ['id' => $user?->id, 'fullName' => $user?->name, 'username' => $user?->username, 'isUser' => true];
    }
}
