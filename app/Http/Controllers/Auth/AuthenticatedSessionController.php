<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteLoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use App\Support\TwoFactorLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        // SCRUM-406: whoever reaches the login page -- signed out, or a session that expired -- leaves no
        // readable history behind: earlier pages' props can't be decrypted, so the back button refetches them.
        Inertia::clearHistory();

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        // SCRUM-34b: with two-factor sign-in on, the code comes next -- nobody is signed in until it's right. Whatever
        // the feature switch says: it only hides setting it up, never protection someone already turned on.
        if ($user->hasTwoFactorEnabled()) {
            TwoFactorLogin::begin($request, $user, $request->boolean('remember'));

            return redirect()->route('two_factor.challenge');
        }

        CompleteLoginAction::new()->execute($request, $user, $request->boolean('remember'));

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // SCRUM-406: after the session is replaced (the flag lives in it), so the next page clears the browser's
        // history -- the back button can't show the chats and admin views from before signing out.
        Inertia::clearHistory();

        return redirect('/');
    }
}
