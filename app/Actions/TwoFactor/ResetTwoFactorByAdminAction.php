<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\TwoFactorReset;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// SCRUM-34c: a super administrator turns off someone's two-factor sign-in -- for a person who lost both their phone and
// their recovery codes, once the team has confirmed who they are (outside the app). Never their own (they turn it off
// themselves, with a code) and never an administrator's (security review: one taken-over super admin mustn't be able
// to strip every other administrator's second factor -- those are reset on the server, `user:two-factor-reset`). When
// the acting super admin has two-factor on, their own code too. SCRUM-424: it schedules the reset (it applies after the
// wait unless cancelled -- RequestTwoFactorResetAction), only for an account with a verified email; this logs who asked.
class ResetTwoFactorByAdminAction extends Action
{
    public function execute(User $admin, User $user, #[\SensitiveParameter] ?string $adminCode, ?string $ip = null, ?string $reason = null): TwoFactorReset
    {
        if (! $admin->isSuperAdmin()) {
            throw new TwoFactorException('Only a super administrator can reset someone\'s two-factor sign-in.', 403);
        }

        if ($admin->is($user)) {
            throw new TwoFactorException('You can turn off your own two-factor sign-in from your profile.', 422);
        }

        if ($user->isAdmin()) {
            throw new TwoFactorException('An administrator\'s two-factor sign-in can only be reset on the server.', 422);
        }

        // SCRUM-424 (the user's decision): the cancel link goes to a verified email -- without one, nobody could stop a
        // reset someone talked the team into. The server command, after an identity check, is the way for them.
        if (! $user->hasVerifiedEmail()) {
            throw new TwoFactorException('This account has no verified email, so the person couldn\'t cancel the reset. Reset it on the server after checking who they are.', 422);
        }

        // Before the admin's own code is checked, so a recovery code isn't used up on a request that's refused anyway
        // (code review). RequestTwoFactorResetAction checks both again under the lock.
        if (! $user->hasTwoFactorEnabled()) {
            throw new TwoFactorException('This account doesn\'t have two-factor sign-in on.', 422);
        }

        if ($pending = $user->pendingTwoFactorReset()->first()) {
            throw new TwoFactorException("A reset is already scheduled for {$pending->due_at->format('j F Y, H:i T')}.", 422);
        }

        if ($admin->hasTwoFactorEnabled() && VerifyTwoFactorCodeAction::new()->execute($admin, (string) $adminCode) === null) {
            throw new TwoFactorException('That code didn\'t work. Use a code from your app or one of your recovery codes.', 422);
        }

        $reset = RequestTwoFactorResetAction::new()->execute($user, $admin, TwoFactorReset::SOURCE_ADMIN, $reason);

        Log::notice('Two-factor sign-in reset requested by an administrator', ['admin_id' => $admin->id, 'user_id' => $user->id, 'reset_id' => $reset->id, 'due_at' => $reset->due_at->toIso8601String(), 'ip' => $ip]);

        return $reset;
    }
}
