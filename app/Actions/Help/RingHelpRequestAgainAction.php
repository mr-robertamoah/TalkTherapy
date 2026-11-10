<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Exceptions\HelpException;
use App\Models\HelpRequest;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-33 (TT-4.4b): an administrator rings every counsellor on call again for a waiting request.
// Returns how many were rung.
class RingHelpRequestAgainAction extends Action
{
    public const TOO_SOON = 'They were just rung. Give them a moment.';

    public function execute(?User $admin, ?HelpRequest $request): int
    {
        $request = EnsureCanHandleHelpRequestAction::new()->execute($admin, $request);

        // Once every 15 seconds a request, however many administrators press it (security review).
        if (! RateLimiter::attempt("help-ring-again:{$request->id}", 1, fn () => true, 15)) {
            throw new HelpException(self::TOO_SOON, 429);
        }

        return RingAvailableCounsellorsAction::new()->execute($request);
    }
}
