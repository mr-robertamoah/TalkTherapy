<?php

namespace App\Http\Requests\Auth;

use App\Actions\Auth\CheckPasswordStrengthAction;
use App\Models\User;
use App\Support\ClientAddress;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required_without:email', 'nullable', 'string'],
            'email' => ['required_without:username', 'nullable', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Check the request's credentials and return the account they belong to -- without signing in: that waits for the
     * second step when the account has two-factor sign-in (SCRUM-34b; CompleteLoginAction).
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();
        // The guard's validate(): the same timing whether or not the account exists, and the usual events -- without
        // signing in (security review).
        $guard = Auth::guard('web');
        $valid = $guard->validate($this->only($this->getMainKey(), 'password'));
        $user = $guard->getLastAttempted();

        if (! $valid || ! $user instanceof User) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                $this->getMainKey() => __('auth.failed'),
            ]);
        }

        // SCRUM-35b: whether this password meets today's policy -- only the result is kept. Here, at the password step:
        // the plain password is never carried on to the second step (SCRUM-34b). The account is the one the
        // credentials matched, never looked up again from the request.
        CheckPasswordStrengthAction::new()->execute($user, (string) $this->input('password'));

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    private function getMainKey()
    {
        return $this->has('email') && $this->input('email', null) ? 'email' : 'username';
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            $this->getMainKey() => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    // Per account and address: the username or email being signed in with (whichever the request uses), and the IP.
    // It used to read only `email`, so every username sign-in from one address (a school, a clinic, a mobile network)
    // shared one counter -- five wrong passwords for anyone locked everyone there out.
    public function throttleKey(): string
    {
        $key = $this->getMainKey();

        // The address as the rest of the app keys it (ClientAddress: hashed, IPv6 by /64), never the raw IP.
        return $key.':'.Str::transliterate(Str::lower((string) $this->input($key))).'|'.ClientAddress::key($this->ip());
    }
}
