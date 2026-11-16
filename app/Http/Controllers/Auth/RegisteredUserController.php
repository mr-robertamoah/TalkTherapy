<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CreateAccountAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleSignIn;
use App\Support\PasswordRules;
use App\Support\PendingGoogleSignUp;
use App\Support\UsernameRules;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): Response
    {
        // SCRUM-454 (security review): an unfinished Google sign-up isn't left for whoever uses this browser next.
        PendingGoogleSignUp::forget($request);

        // SCRUM-454: "Continue with Google" here too -- a new Google account finishes setting up afterwards.
        return Inertia::render('Auth/Register', [
            'googleSignIn' => GoogleSignIn::available(),
        ]);
    }

    public const TOO_MANY = CreateAccountAction::TOO_MANY;

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // SCRUM-451: validated inside the action's per-address limit, so a rejected form gives its count back.
        $user = CreateAccountAction::new()->execute($request->ip(), function () use ($request) {
            $request->validate([
                'username' => UsernameRules::for(),
                'email' => ['required_without:username', 'email', 'unique:'.User::class],
                'password' => PasswordRules::for(),
            ]);

            return $request->only('username', 'email', 'password');
        });

        event(new Registered($user));

        Auth::login($user);

        return response()->redirectTo('/preferences');
    }
}
