<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ClientAddress;
use App\Support\PasswordRules;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public const TOO_MANY = 'Too many accounts have been created from this network recently. Please try again later. If you need help urgently, please contact your local emergency services or a crisis line.';

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // SCRUM-400: accounts aren't free -- every per-account limit (urgent help's included) relies on it.
        // Counted first, so requests at the same moment can't all slip under the limit; given back if this
        // one doesn't end in an account (a typo or a taken username never counts).
        $address = ClientAddress::key($request->ip());
        $key = "registrations:{$address}";
        if (RateLimiter::increment($key, 3600) > (int) config('user.registrations_per_hour_per_address')) {
            RateLimiter::decrement($key, 3600);
            Log::warning('Registration refused: too many accounts from one address.', ['address' => $address]);

            throw ValidationException::withMessages(['username' => self::TOO_MANY]);
        }

        try {
            $user = $this->createAccount($request);
        } catch (Throwable $th) {
            RateLimiter::decrement($key, 3600);

            throw $th;
        }

        event(new Registered($user));

        Auth::login($user);

        return response()->redirectTo('/preferences');
    }

    private function createAccount(Request $request): User
    {
        $request->validate([
            'username' => ['required', 'alpha_dash', 'max:255', 'unique:'.User::class],
            'email' => ['required_without:username', 'email', 'unique:'.User::class],
            'password' => PasswordRules::for(),
        ]);

        return User::create([
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
    }
}
