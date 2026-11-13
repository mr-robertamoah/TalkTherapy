<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// Only ever set on a GroupTherapy earning paid by a member's own card -- null for an individual Therapy/Session
// earning (the therapy's sole counsellor at 100%) and for any organisation-paid earning (SCRUM-420: its split is
// the organisation's terms with each counsellor, recorded on transactions.breakdown).
enum CounsellorEarningShareBasisEnum: string
{
    use EnumTrait;

    case equal = 'EQUAL';
    case percentage = 'PERCENTAGE';
}
