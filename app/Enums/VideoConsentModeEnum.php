<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-3.1e-a/SCRUM-280: a per-Therapy setting (Therapy::video_consent_mode), not per-consent-grant
// -- settable by either the assigned counsellor or any guardian of the minor client. Mode
// switches are prospective-only (architect decision, 2026-09-11): changing this later never
// retroactively invalidates an existing valid VideoConsent grant for the other scope.
enum VideoConsentModeEnum: string
{
    use EnumTrait;

    case per_therapy = 'PER_THERAPY';
    case per_session = 'PER_SESSION';
}
