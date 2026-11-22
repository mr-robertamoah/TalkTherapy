<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-7.11d1/SCRUM-473: a super administrator's decision about a counsellor for a month (counsellor_recognitions.decision).
enum CounsellorRecognitionDecisionEnum: string
{
    use EnumTrait;

    case approved = 'approved';
    case skipped = 'skipped';
}
