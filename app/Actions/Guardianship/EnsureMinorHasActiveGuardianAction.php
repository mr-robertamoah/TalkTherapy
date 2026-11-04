<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Exceptions\GuardianRequiredException;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-288: refuses a write in a therapy while it is paused for a missing guardian -- when $actor
// is a minor with no active guardian taking part as a client or member, and ($counterparty) when
// $actor is the counsellor of an individual therapy whose client is one (the user's decision: that
// counsellor is read-only too). Someone acting as the therapy's own counsellor is never the minor
// it is paused for, so their own age isn't asked. In a group only the actor's own state matters:
// the others carry on. Reads are never checked here. Adults and administrators are answered
// without a query (User::isPausedForMissingGuardian()). $fresh re-reads (inside a lock).
class EnsureMinorHasActiveGuardianAction extends Action
{
    // $actsAsCounsellor: when the caller knows the actor answers as a counsellor (a request addressed
    // to their counsellor profile) even if the therapy no longer lists them; otherwise worked out here.
    public function execute(?User $actor, Therapy|GroupTherapy|null $therapy = null, bool $counterparty = true, bool $fresh = false, ?bool $actsAsCounsellor = null): void
    {
        if (! $actor) {
            return;
        }

        $actsAsCounsellor ??= $therapy && $actor->counsellor && $therapy->isCounsellor($actor->counsellor);

        if (! $actsAsCounsellor && $actor->isPausedForMissingGuardian($fresh)) {
            throw GuardianRequiredException::forViewer();
        }

        if (! $counterparty || ! $actsAsCounsellor || ! $therapy instanceof Therapy) {
            return;
        }

        $client = $therapy->addedby_type === User::class ? $therapy->addedby : null;

        if ($client && $client->isPausedForMissingGuardian($fresh)) {
            throw GuardianRequiredException::forClient();
        }
    }
}
