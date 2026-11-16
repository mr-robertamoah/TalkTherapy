<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\Models\User;
use App\Support\ClientAddress;
use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

// SCRUM-451: the one way an account is created -- the registration form today, Google sign-in next (SCRUM-112) -- so
// the per-address limit (SCRUM-400) and the password bookkeeping (SCRUM-35b) can't be skipped by a second sign-up path.
// $input runs inside the limit and is responsible for validation (this action validates nothing itself): it returns the
// account's details -- a username, optionally an email, and `password` (a plain password, hashed here, or an explicit
// null for an identity-provider sign-up with no password; leaving the key out is an error, so a form can't create a
// password-less account by forgetting it). Firing Registered and signing in stay with the caller.
class CreateAccountAction extends Action
{
    // What else a caller may set on the new account, besides the username, email and password. Anything else is ignored.
    private const OPTIONAL_DETAILS = ['google_id', 'google_linked_at', 'email_verified_at', 'dob'];

    public const TOO_MANY = 'Too many accounts have been created from this network recently. Please try again later. If you need help urgently, please contact your local emergency services or a crisis line.';

    /**
     * @param  Closure(): array{username: string, email?: ?string, password: ?string, google_id?: string, google_linked_at?: mixed, email_verified_at?: mixed, dob?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function execute(?string $ip, Closure $input): User
    {
        // SCRUM-400: accounts aren't free -- every per-account limit (urgent help's included) relies on it.
        // Counted first, so requests at the same moment can't all slip under the limit; given back if this
        // one doesn't end in an account (a typo or a taken username never counts).
        $address = ClientAddress::key($ip);
        $key = "registrations:{$address}";
        if (RateLimiter::increment($key, 3600) > (int) config('user.registrations_per_hour_per_address')) {
            RateLimiter::decrement($key, 3600);
            Log::warning('Registration refused: too many accounts from one address.', ['address' => $address]);

            throw ValidationException::withMessages(['username' => self::TOO_MANY]);
        }

        try {
            return $this->create($input());
        } catch (Throwable $th) {
            RateLimiter::decrement($key, 3600);

            throw $th;
        }
    }

    private function create(array $details): User
    {
        if (! array_key_exists('password', $details)) {
            throw new InvalidArgumentException('An account needs a password, or an explicit null for a password-less one.');
        }

        $password = $details['password'];

        // One insert, with any of the optional details a sign-up path gives (SCRUM-454: Google's id, a confirmed email, a
        // date of birth), so a unique clash at the same moment happens here -- and the limit's count is given back.
        $user = (new User)->forceFill([
            'username' => $details['username'],
            'email' => $details['email'] ?? null,
            'password' => $password === null ? null : Hash::make($password),
            ...array_intersect_key($details, array_flip(self::OPTIONAL_DETAILS)),
        ]);
        $user->save();

        // SCRUM-35b: it has just met the whole policy, the breached-list check included.
        if ($password !== null && config('auth.password_policy.uncompromised')) {
            $user->forceFill(['password_checked_at' => now()])->save();
        }

        return $user;
    }
}
