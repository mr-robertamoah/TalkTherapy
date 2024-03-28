<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum RequestTypeEnum: string
{
    use EnumTrait;
    case counsellor = 'COUNSELLOR_VERIFICATION_REQUEST';
    case administrator = 'ADMINISTRATION_REQUEST';
    case discussion = 'THERAPY_DISCUSSION_REQUEST';
    case therapy = 'THERAPY_ASSISTANCE_REQUEST';
    case groupTherapy = 'GROUP_THERAPY_ASSISTANCE_REQUEST';
}