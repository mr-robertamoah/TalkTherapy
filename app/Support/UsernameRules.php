<?php

namespace App\Support;

use App\Models\User;

// SCRUM-454: the one set of rules for a new account's username -- the registration form and Google's "Finish setting
// up" step -- so the two sign-up paths can't drift apart.
final class UsernameRules
{
    public static function for(): array
    {
        return ['required', 'alpha_dash', 'max:255', 'unique:'.User::class];
    }
}
