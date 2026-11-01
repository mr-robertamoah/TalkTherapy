<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Exceptions\SuspensionException;
use App\Models\Counsellor;

// SCRUM-359: the one check that a counsellor isn't suspended (either scope), called wherever a
// counsellor acts as one (accepting work, running sessions, writing as a counsellor) or is asked to
// ($asTarget: someone sends them a request). Reading what they already have is never blocked, and
// neither is reporting someone.
class EnsureCanActAsCounsellorAction extends Action
{
    public const SUSPENDED = 'Your counsellor role is suspended, so you cannot do this right now.';

    public const UNAVAILABLE = 'This counsellor is not available right now.';

    public function execute(mixed $counsellor, bool $asTarget = false): void
    {
        if ($counsellor instanceof Counsellor && $counsellor->isSuspended()) {
            throw new SuspensionException($asTarget ? self::UNAVAILABLE : self::SUSPENDED, 403);
        }
    }
}
