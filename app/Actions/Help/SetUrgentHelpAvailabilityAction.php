<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Exceptions\HelpException;
use App\Models\User;

// SCRUM-33: a counsellor switches "available for urgent help" on (for config help.availability_hours,
// after which it lapses on its own) or off. Only a currently verified, unsuspended counsellor can be on
// call; switching off is always allowed.
class SetUrgentHelpAvailabilityAction extends Action
{
    public const NOT_COUNSELLOR = 'Only counsellors can be available for urgent help.';

    public const NOT_ELIGIBLE = 'You can be available for urgent help while your verification is current and your counsellor role isn\'t suspended.';

    public function execute(User $user, bool $available): ?string
    {
        $counsellor = $user->counsellor;
        if (! $counsellor) {
            throw new HelpException(self::NOT_COUNSELLOR, 403);
        }

        if ($available && (! $counsellor->isVerified() || $counsellor->isSuspended())) {
            throw new HelpException(self::NOT_ELIGIBLE, 422);
        }

        $counsellor->forceFill([
            'urgent_help_available_until' => $available ? now()->addHours((int) config('help.availability_hours')) : null,
        ])->save();

        return $counsellor->urgent_help_available_until?->toIso8601String();
    }
}
