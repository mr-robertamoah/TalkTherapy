<?php

namespace App\Enums;
use App\Traits\EnumTrait;

enum LicensingAuthorityTypeEnum : string {
    use EnumTrait;

    case govermental = 'GOVERMENTAL';
    case international = 'INTERNATIONAL';
    case religious = 'RELIGIOUS';
    case other = 'OTHER';
}