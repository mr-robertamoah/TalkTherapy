<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-7.11d1/SCRUM-473: what happened to a recognition (counsellor_recognition_events.event).
enum CounsellorRecognitionEventEnum: string
{
    use EnumTrait;

    // Decisions, by a super administrator. `added` is an approval of a counsellor who wasn't suggested.
    case approved = 'approved';
    case skipped = 'skipped';
    case added = 'added';

    // Consent, by the counsellor.
    case consentAccepted = 'consent_accepted';
    case consentDeclined = 'consent_declined';
    case consentWithdrawn = 'consent_withdrawn';
    case consentRestored = 'consent_restored';
}
