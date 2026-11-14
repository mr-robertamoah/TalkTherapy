<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// SCRUM-34c: a super administrator turns off someone's two-factor sign-in -- for a person who lost both their phone and
// their recovery codes, once the team has confirmed who they are (outside the app). Never their own (they turn it off
// themselves, with a code) and never an administrator's (security review: one taken-over super admin mustn't be able
// to strip every other administrator's second factor -- those are reset on the server, `user:two-factor-reset`). When
// the acting super admin has two-factor on, their own code too. The reset itself is ResetTwoFactorAction; this logs who
// did it.
class ResetTwoFactorByAdminAction extends Action
{
    public function execute(User $admin, User $user, #[\SensitiveParameter] ?string $adminCode, ?string $ip = null): void
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

        if ($admin->hasTwoFactorEnabled() && VerifyTwoFactorCodeAction::new()->execute($admin, (string) $adminCode) === null) {
            throw new TwoFactorException('That code didn\'t work. Use a code from your app or one of your recovery codes.', 422);
        }

        ResetTwoFactorAction::new()->execute($user);

        Log::notice('Two-factor sign-in reset by an administrator', ['admin_id' => $admin->id, 'user_id' => $user->id, 'ip' => $ip]);
    }
}
