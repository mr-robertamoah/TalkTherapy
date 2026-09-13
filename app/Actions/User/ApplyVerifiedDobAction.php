<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Therapy;
use App\Models\User;

// TT-4.11c/SCRUM-304: extracted from RespondToDobChangeRequestAction's own
// applyApprovedDobChange() so "what does it mean to correct a person's historical minor status"
// has exactly one implementation, reused by BOTH RespondToDobChangeRequestAction (an ordinary,
// unverified guardian-approved edit) and RespondToAgeVerificationRequestAction (an admin-verified
// one) -- the exact class of drift TT-4.10f's own closeout had to fix three times over for a
// different piece of logic. Must be called from inside the caller's own existing locked
// transaction (both callers already lockForUpdate() the target User row before calling this) --
// this action does not open a second transaction.
class ApplyVerifiedDobAction extends Action
{
    // TT-4.10c/SCRUM-292's own explicit decision: an approved change is a CORRECTION of the
    // truth, not a prospective-only change (unlike TT-3.1e-a's deliberately prospective-only
    // video-consent-mode switch -- that one protects a past grant from being retroactively
    // invalidated; this one corrects a person's actual historical age). Every currently-existing
    // qualifying record is updated to match the now-confirmed dob, not just the user's own
    // column -- otherwise TT-4.10b's own snapshot-preferring call sites would keep enforcing the
    // stale, pre-approval status forever.
    //
    // $verified marks whether this specific dob value has been admin-verified (an approved
    // ageVerification request) rather than merely self-reported/guardian-approved (an ordinary
    // dobChange). A later, unverified dobChange approval for the same user must explicitly clear
    // `dob_verified_at` (not just leave it stale) -- otherwise the system would keep treating a
    // brand new, never-verified dob value as if it still carried the old verification.
    public function execute(User $user, ?string $dob, bool $verified = false): void
    {
        $user->update([
            'dob' => $dob,
            'dob_verified_at' => $verified ? now() : null,
        ]);

        $isMinor = ! $user->refresh()->isAdult();

        Guardianship::query()->where('ward_id', $user->id)->update(['ward_was_minor_at_creation' => $isMinor]);

        Therapy::query()->where('addedby_type', User::class)->where('addedby_id', $user->id)
            ->update(['client_was_minor_at_creation' => $isMinor]);

        GroupTherapy::query()->where('addedby_type', User::class)->where('addedby_id', $user->id)
            ->update(['client_was_minor_at_creation' => $isMinor]);
    }
}
