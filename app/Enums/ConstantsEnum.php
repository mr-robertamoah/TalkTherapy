<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum ConstantsEnum: string
{
    use EnumTrait;

    case nationalId = 'National Identification Authority';
    case anonymousUserLabel = 'Client (Anonymous User)';

    // TT-3.2a/SCRUM-308: DailyVideoProvider's own room-size cap must be computed per session type,
    // not a single flat value (architect finding) -- 1:1 Therapy stays capped near 2; GroupTherapy
    // needs headroom for the active counsellor team plus its members.
    //
    // TT-3.2f-a/SCRUM-318: raised 10 -> 25 and unified with the group's own membership ceiling
    // (GROUP_THERAPY_MAX_USERS, see EnsureTherapyDataIsValidAction) -- SCRUM-314/TT-3.2f opens
    // video to the full membership (receive-only + raise-hand/grant-to-speak, not everyone getting
    // full two-way access), so this is no longer "counsellors + 1 creator" but "counsellors + every
    // member," and the two ceilings are now deliberately the same number so a group's video room
    // can always admit its full membership (user's own explicit decision, 2026-09-14).
    case therapyVideoMaxParticipants = '2';
    case groupTherapyVideoMaxParticipants = '25';
}
