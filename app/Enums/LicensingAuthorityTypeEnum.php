<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum LicensingAuthorityTypeEnum : string {
    use EnumTrait;

    case govermental = 'govermental';
    case international = 'international';
    case religious = 'religious';
    case other = 'other';
}