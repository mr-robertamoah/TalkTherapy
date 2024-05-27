<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum LinkTypeEnum : string {
    use EnumTrait;

    case discussion = 'DISCUSSION';
    case guardianship = 'GUARDIANSHIP';
    case groupTherapyAdmin = 'GROUP_THERAPY_ADMIN';
    case groupTherapyParticipant = 'GROUP_THERAPY_PARTICIPANT';
    case groupTherapyCounsellor = 'GROUP_THERAPY_COUNSELLOR';
    case therapyCounsellor = 'THERAPY_COUNSELLOR';
}