<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-3.2f-b/SCRUM-319: distinguishes a counsellor's own deliberate revoke from the two automatic
// paths (an idle sweep, or the participant being removed from the call entirely) so a grant's
// audit trail can explain WHY it ended, not just that it did -- mirrors
// VideoConsentRevocationReasonEnum's identical rationale.
enum SpeakingPermissionRevocationReasonEnum: string
{
    use EnumTrait;

    case manual = 'MANUAL';
    case idle_auto_expiry = 'IDLE_AUTO_EXPIRY';
    case participant_removed = 'PARTICIPANT_REMOVED';
    // SCRUM-329: an under-18's guardians' consent no longer lets them speak (watch only, or withdrawn).
    case guardian_consent = 'GUARDIAN_CONSENT';
}
