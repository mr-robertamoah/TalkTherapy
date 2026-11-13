<?php

namespace App\Support;

use App\Models\User;
use App\Rules\PasswordDoesNotContainIdentity;
use Illuminate\Validation\Rules\Password;

// SCRUM-35: the one set of rules for a new password -- signing up, resetting and changing it. The policy itself is
// Password::defaults() (AppServiceProvider: config('auth.password_policy')); this adds what needs the account.
class PasswordRules
{
    public static function for(?User $user = null): array
    {
        return ['required', 'confirmed', Password::defaults(), new PasswordDoesNotContainIdentity($user)];
    }

    // What the forms show before anyone types (HandleInertiaRequestsV2 shares it), from the same configuration.
    public static function hints(): array
    {
        return [
            'min' => (int) config('auth.password_policy.min'),
            'uncompromised' => (bool) config('auth.password_policy.uncompromised'),
        ];
    }
}
