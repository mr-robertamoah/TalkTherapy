<?php

namespace App\Http\Requests\Auth;

use App\Jobs\StoreVisitationJob;
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
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();
        $key = $this->getMainKey();

        if (! Auth::attempt($this->only($key, 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                $this->getMainKey() => __('auth.failed'),
            ]);
        }

        if ($user = User::where($key, $this->get($key))->first()) {
            StoreVisitationJob::dispatch($user, $this->ip());
        }

        RateLimiter::clear($this->throttleKey());
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
