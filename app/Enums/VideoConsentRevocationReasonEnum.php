<?php

namespace App\Enums;

use App\Traits\EnumTrait;

// TT-3.1e-a/SCRUM-280: distinguishes a guardian's own deliberate revoke from an automatic lapse
// (a Guardianship being deleted) so a VideoConsent's audit trail can explain WHY a grant ended,
// not just that it did -- added per an architect suggestion the user explicitly agreed to.
enum VideoConsentRevocationReasonEnum: string
{
    use EnumTrait;

    case guardian_action = 'GUARDIAN_ACTION';
    case guardianship_removed = 'GUARDIANSHIP_REMOVED';
    // SCRUM-313: the ward left the group the grant was for.
    case membership_ended = 'MEMBERSHIP_ENDED';
    // SCRUM-329: replaced by a new grant for the same scope -- a level change, or a fresh grant over a grant from a
    // guardian who no longer counts. Not a withdrawal: the history says the consent went on.
    case superseded = 'SUPERSEDED';
}
