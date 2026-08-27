<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum OrganizationCounsellorCompensationBasisEnum: string
{
    use EnumTrait;

    // Only meaningful when the compensation type is `percentage` -- percentage OF WHAT.
    case counsellorRate = 'COUNSELLOR_RATE';
    case negotiatedRate = 'NEGOTIATED_RATE';
}
