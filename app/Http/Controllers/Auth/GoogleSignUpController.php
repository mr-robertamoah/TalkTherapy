<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteLoginAction;
use App\Actions\Auth\CreateAccountAction;
use App\Http\Controllers\Controller;
use App\Models\BlockedGoogleIdentity;
use App\Models\User;
use App\Support\DobRules;
use App\Support\PendingGoogleSignUp;
use App\Support\UsernameRules;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// SCRUM-454 (Google sign-in, SCRUM-112): "Finish setting up" for a Google account connected to nothing
// (GoogleSignInController::startSignUp). A username and, optionally, a date of birth -- then the account is made
// through CreateAccountAction (the per-address limit included), connected to Google, with no password.
class GoogleSignUpController extends Controller
{
    public const EXPIRED = 'That sign-up has expired. Continue with Google again.';

    public const TRY_AGAIN = "We couldn't create your account just then. Please try again.";

    public function create(Request $request): Response|RedirectResponse
    {
        if (! PendingGoogleSignUp::current($request)) {
            return $this->expired($request);
        }

        return Inertia::render('Auth/GoogleFinishSignUp');
    }

    public function store(Request $request): RedirectResponse
    {
        $pending = PendingGoogleSignUp::current($request);

        if (! $pending) {
            return $this->expired($request);
        }

        try {
            $user = $this->createAccount($request, $pending);
        } catch (UniqueConstraintViolationException) {
            // Taken at the same moment, by another tab or another sign-up (the count is given back already). The Google
            // account: it's connected now, so continuing with Google signs in. The username or the email: the sign-up
            // stays, to try again -- a taken email is then left out, as when Google answered.
            if (User::query()->withTrashed()->where('google_id', $pending['sub'])->exists()) {
                return $this->expired($request);
            }

            throw ValidationException::withMessages(['username' => User::query()->withTrashed()->where('username', $request->input('username'))->exists()
                ? trans('validation.unique', ['attribute' => 'username'])
                : self::TRY_AGAIN]);
        }

        event(new Registered($user));

        PendingGoogleSignUp::forget($request);

        // Never remembered (Google sign-ins aren't), and a new account has no two-factor to ask for.
        CompleteLoginAction::new()->execute($request, $user, remember: false);

        return redirect('/preferences');
    }

    /**
     * @param  array{sub: string, email: ?string}  $pending
     *
     * @throws ValidationException
     */
    private function createAccount(Request $request, array $pending): User
    {
        // Validated inside the per-address limit, so a rejected form gives its count back (as for registration). One
        // insert (CreateAccountAction), so nothing is ever half-made.
        return CreateAccountAction::new()->execute($request->ip(), function () use ($request, $pending) {
            $request->validate([
                'username' => UsernameRules::for(),
                'dob' => DobRules::for(),
            ]);

            // Connected to an account since Google answered (another tab finished first): nothing is made. Thrown, so
            // the limit's count is given back; sent to sign-in, where continuing with Google now signs in.
            // SCRUM-456: likewise if, meanwhile, an administrator closed an account it was connected to (blocked).
            if (User::query()->withTrashed()->where('google_id', $pending['sub'])->exists() || BlockedGoogleIdentity::isBlocked($pending['sub'])) {
                PendingGoogleSignUp::forget($request);

                throw ValidationException::withMessages(['google' => self::EXPIRED])->redirectTo(route('login'));
            }

            // Checked again: an address taken meanwhile just isn't kept, as when Google answered.
            $email = $pending['email'] && PendingGoogleSignUp::emailIsFree($pending['email']) ? $pending['email'] : null;

            return [
                'username' => $request->input('username'),
                'email' => $email,
                'password' => null,
                'google_id' => $pending['sub'],
                'google_linked_at' => now(),
                // Only an address Google vouches for (Gmail or Workspace) gets this far (PendingGoogleSignUp).
                'email_verified_at' => $email ? now() : null,
                'dob' => $request->input('dob') ?: null,
            ];
        });
    }

    private function expired(Request $request): RedirectResponse
    {
        PendingGoogleSignUp::forget($request);

        return redirect()->route('login')->withErrors(['google' => self::EXPIRED]);
    }
}
