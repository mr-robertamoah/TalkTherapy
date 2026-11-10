<?php

namespace App\Actions\VideoConsent;

use App\Actions\Action;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-3.1e-b/SCRUM-281: the one place "who is the minor this consent scope is actually about"
// gets resolved -- a PER_THERAPY grant's consentable is the Therapy itself; a PER_SESSION grant's
// consentable is a Session, whose ward is its parent Therapy's own client. TT-3.1 (and therefore
// this whole consent feature) is 1:1 individual-Therapy-only, so the client is always the
// Therapy's addedby -- GroupTherapy has no single "the minor" to resolve and isn't reachable here.
class GetWardForVideoConsentableAction extends Action
{
    public function execute(Therapy|Session $consentable): ?User
    {
        $therapy = $this->therapyFor($consentable);

        if (! $therapy instanceof Therapy) {
            return null;
        }

        if ($therapy->addedby_type !== User::class || ! $therapy->addedby) {
            return null;
        }

        return $therapy->addedby;
    }

    public function therapyFor(Therapy|Session $consentable): ?Therapy
    {
        if ($consentable instanceof Therapy) {
            return $consentable;
        }

        // A Session's `for` can be a GroupTherapy, which has no single "the minor" to resolve
        // and isn't a valid consentable scope -- return null (handled the same as "no ward")
        // rather than letting the ?Therapy return type throw a TypeError on the caller.
        return $consentable->for instanceof Therapy ? $consentable->for : null;
    }

    // TT-4.10b/SCRUM-291: every caller here used to re-derive "is the ward a minor" via a live
    // $ward->isAdult() check -- exactly the self-editable-dob bypass SCRUM-287 found. Prefers
    // the resolved Therapy's own stable client_was_minor_at_creation snapshot (TT-4.10a) via
    // Therapy::clientIsMinor() instead.
    public function isMinor(Therapy|Session $consentable): bool
    {
        $therapy = $this->therapyFor($consentable);

        return $therapy instanceof Therapy && $therapy->clientIsMinor();
    }
}
