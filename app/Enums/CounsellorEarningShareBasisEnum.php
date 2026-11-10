<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// Only ever set on a GroupTherapy earning row -- null for an individual Therapy/Session earning
// (which is always the therapy's sole counsellor at 100%, nothing to record a basis for).
enum CounsellorEarningShareBasisEnum: string
{
    use EnumTrait;

    case equal = 'EQUAL';
    case percentage = 'PERCENTAGE';
}
