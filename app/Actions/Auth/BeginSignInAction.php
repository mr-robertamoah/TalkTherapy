<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Support\TwoFactorLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// SCRUM-451: what happens once someone has proved who they are -- by password today, by Google next (SCRUM-112) --
// decided in one place, so no sign-in path can skip the two-factor code.
class BeginSignInAction extends Action
{
    public function execute(Request $request, User $user, bool $remember): RedirectResponse
    {
        // SCRUM-34b: with two-factor sign-in on, the code comes next -- nobody is signed in until it's right. Whatever
        // the feature switch says: it only hides setting it up, never protection someone already turned on.
        if ($user->hasTwoFactorEnabled()) {
            TwoFactorLogin::begin($request, $user, $remember);

            return redirect()->route('two_factor.challenge');
        }

        CompleteLoginAction::new()->execute($request, $user, $remember);

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
