<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\BeginSignInAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\GoogleSignIn;
use App\Support\PendingGoogleSignUp;
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
    public function create(Request $request): Response
    {
        // SCRUM-406: whoever reaches the login page -- signed out, or a session that expired -- leaves no
        // readable history behind: earlier pages' props can't be decrypted, so the back button refetches them.
        Inertia::clearHistory();

        // SCRUM-454 (security review): an unfinished Google sign-up isn't left for whoever uses this browser next.
        PendingGoogleSignUp::forget($request);

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            // SCRUM-453: "Continue with Google" only once it is switched on and the Google client is configured.
            'googleSignIn' => GoogleSignIn::available(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        return BeginSignInAction::new()->execute($request, $user, $request->boolean('remember'));
    }

    // SCRUM-423: an open page asking whether it is still signed in -- as the person it was shown to (security review:
    // on a shared browser someone else may have signed in since). Behind `auth`, so an ended session is a 401 already.
    public function check(Request $request): \Illuminate\Http\Response
    {
        abort_unless((string) $request->query('user') === (string) $request->user()->id, 401);

        return response()->noContent();
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
