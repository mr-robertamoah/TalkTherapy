<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CreateAccountAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordRules;
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
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
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
                'username' => ['required', 'alpha_dash', 'max:255', 'unique:'.User::class],
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
