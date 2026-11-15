<?php

namespace App\Support;

use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-443 (the user's decision, 2026-11-15): in-person therapy is for adults only. A therapy or group that allows
// in-person sessions has no client or member under 18, and the settings say so; guardian consent for meeting in person
// is left for later. "Under 18" is User::isAdult()'s rule (no date of birth counts as under 18).
final class InPerson
{
    public const ADULTS_ONLY = 'In-person sessions are for adults (18 and over) only, so they can\'t be allowed while someone under 18 is taking part.';

    // To someone who isn't the group's counsellor (security review): nothing about another member's age.
    public const NOT_AVAILABLE = 'In-person sessions aren\'t available for this group right now.';

    // The adults-only reason for the therapy's counsellors (and administrators), and for a 1:1's own client; a group
    // member or user creator gets a neutral one.
    public static function refusalFor(?User $user, Therapy|GroupTherapy $therapy): string
    {
        if ($therapy instanceof Therapy || ! $user || $user->isAdmin() || $therapy->isCounsellorUser($user)) {
            return self::ADULTS_ONLY;
        }

        return self::NOT_AVAILABLE;
    }

    public const MINOR_CANNOT_JOIN = 'This group meets in person, which is for adults (18 and over) only.';

    // The client of a 1:1, or any current member of a group (and a group's user creator).
    public static function hasMinorClient(Therapy|GroupTherapy $therapy): bool
    {
        if ($therapy instanceof Therapy) {
            return $therapy->addedby instanceof User && ! $therapy->addedby->isAdult();
        }

        if ($therapy->addedby instanceof User && ! $therapy->addedby->isAdult()) {
            return true;
        }

        return $therapy->users()->whereNotAdult()->exists();
    }
}
