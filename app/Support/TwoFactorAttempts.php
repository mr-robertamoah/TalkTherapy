<?php

namespace App\Support;

use App\Exceptions\TwoFactorException;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-34b (security review): one count of two-factor codes per account, whichever step they were typed at -- signing
// in, confirming setup, new recovery codes, turning it off -- so splitting guesses across routes gains nothing. Each
// try is counted before the code is checked (so requests sent at once can't all slip under the limit), and a right
// code clears the count. Five wrong and the account's codes are refused for 15 minutes; the person can still reset
// their password, and someone who knows it could keep them locked out that way -- accepted (decision log).
final class TwoFactorAttempts
{
    public const MAX = 5;

    public const DECAY_SECONDS = 900;

    /**
     * @template T
     *
     * @param  Closure(): T  $check  the code check; a falsy result counts as a wrong code
     * @return T
     */
    public static function attempt(User $user, Closure $check): mixed
    {
        $key = 'two-factor:'.$user->id;

        if (RateLimiter::increment($key, self::DECAY_SECONDS) > self::MAX) {
            $minutes = (int) ceil(max(1, RateLimiter::availableIn($key)) / 60);

            throw new TwoFactorException("Too many wrong codes. Please try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.', 429);
        }

        $result = $check();

        if ($result) {
            RateLimiter::clear($key);
        }

        return $result;
    }
}
