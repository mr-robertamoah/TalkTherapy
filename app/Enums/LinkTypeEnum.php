<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum LinkTypeEnum: string
{
    use EnumTrait;

    case discussion = 'DISCUSSION';
    case guardianship = 'GUARDIANSHIP';
    case groupTherapyAdmin = 'GROUP_THERAPY_ADMIN';
    case groupTherapyParticipant = 'GROUP_THERAPY_PARTICIPANT';
    case groupTherapyCounsellor = 'GROUP_THERAPY_COUNSELLOR';
    case therapyCounsellor = 'THERAPY_COUNSELLOR';
    // A shareable (to=null), admin-generated link an org hands out for member self-apply (SCRUM-164)
    // -- distinct from the public directory (TT-6.6c), which requires a member to find the org
    // themselves.
    case organizationSelfApply = 'ORGANIZATION_SELF_APPLY';
}
