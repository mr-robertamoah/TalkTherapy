<?php

namespace App\Support;

use App\Models\User;

// SCRUM-454: the one set of rules for a new account's username -- the registration form and Google's "Finish setting
// up" step -- so the two sign-up paths can't drift apart. Usernames are only ever set when an account is made (the
// profile and admin edits don't change them), so these two are every place one is validated.
// SCRUM-456: ASCII only, so a look-alike Unicode letter can't pass for someone else's username. Existing usernames are
// left as they are.
final class UsernameRules
{
    public static function for(): array
    {
        return ['required', 'alpha_dash:ascii', 'max:255', 'unique:'.User::class];
    }
}
