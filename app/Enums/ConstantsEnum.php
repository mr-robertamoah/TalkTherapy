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
    // needs headroom for the active counsellor team (unbounded in the data model -- `max_counsellors`
    // has no upper bound, see CreateGroupTherapyRequest) plus, per this ticket's own locked v1
    // scope, at most one client (the group's own creator; ordinary members get no video access at
    // all in this version). 10 comfortably covers a realistic counsellor-team size with headroom,
    // while staying meaningfully smaller than the 50-person full-membership number a future
    // full-membership ticket (SCRUM-314) would need its own, much larger value for.
    case therapyVideoMaxParticipants = '2';
    case groupTherapyVideoMaxParticipants = '10';
}
