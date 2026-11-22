<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-7.11d1/SCRUM-473: whether a recognised counsellor agreed to be featured (counsellor_recognitions.consent; null
// for a skipped row). pending -> accepted | declined (answering the consent request); accepted | pending -> withdrawn;
// withdrawn | declined -> accepted (opting back in). Only the counsellor changes it once it is pending.
enum CounsellorRecognitionConsentEnum: string
{
    use EnumTrait;

    case pending = 'pending';
    case accepted = 'accepted';
    case declined = 'declined';
    case withdrawn = 'withdrawn';

    public function canBeWithdrawn(): bool
    {
        return in_array($this, [self::accepted, self::pending], true);
    }

    public function canBeRestored(): bool
    {
        return in_array($this, [self::withdrawn, self::declined], true);
    }
}
