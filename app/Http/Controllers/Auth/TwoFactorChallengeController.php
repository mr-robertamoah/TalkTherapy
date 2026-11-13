<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteLoginAction;
use App\Actions\TwoFactor\VerifyTwoFactorCodeAction;
use App\Exceptions\TwoFactorException;
use App\Http\Controllers\Controller;
use App\Notifications\TwoFactorChangedNotification;
use App\Providers\RouteServiceProvider;
use App\Support\TwoFactorLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// SCRUM-34b: the second step of signing in -- a code from the authenticator app, or a recovery code. Only reachable
// after the right password, for 5 minutes; otherwise back to the sign-in page.
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! TwoFactorLogin::user($request)) {
            return $this->restart($request);
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = TwoFactorLogin::user($request);

        if (! $user) {
            return $this->restart($request);
        }

        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);

        try {
            $used = VerifyTwoFactorCodeAction::new()->execute($user, $validated['code']);
        } catch (TwoFactorException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        if (! $used) {
            throw ValidationException::withMessages(['code' => 'That code didn\'t work. Try the newest code from your app, or a recovery code.']);
        }

        $remember = TwoFactorLogin::remember($request);
        TwoFactorLogin::forget($request);
        CompleteLoginAction::new()->execute($request, $user, $remember);

        // A recovery code is a lost-phone route: the person hears of it, so a stolen code can't be used unseen.
        if ($used === VerifyTwoFactorCodeAction::RECOVERY) {
            $user->notify(new TwoFactorChangedNotification(TwoFactorChangedNotification::RECOVERY_CODE_USED));
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    private function restart(Request $request): RedirectResponse
    {
        TwoFactorLogin::forget($request);

        return redirect()->route('login')->with('status', 'Please sign in again.');
    }
}
