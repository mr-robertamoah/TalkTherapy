<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\Models\User;
use App\Rules\PasswordDoesNotContainIdentity;
use App\Support\PasswordRules;
use App\Support\PwnedPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

use function Illuminate\Support\defer;

// SCRUM-35b: at sign-in -- the one moment the plain password exists -- whether it meets today's policy (SCRUM-35a).
// Only the result is kept (users.password_weak_reason), never the password; the profile says so until it's changed.
// Never slows or stops a sign-in: the quick rules (length, letters and numbers, not the username or email) run here;
// the breached-list check runs after the response is sent (defer(): the password stays in this process's memory, never
// queued or stored), and only once per password (password_checked_at, cleared when it changes). Any failure just
// leaves the flag as it was.
class CheckPasswordStrengthAction extends Action
{
    public const SHORT_OR_SIMPLE = 'short_or_simple';

    public const CONTAINS_IDENTITY = 'identity';

    public const BREACHED = 'breached';

    public function execute(User $user, #[\SensitiveParameter] string $password): void
    {
        try {
            // A breach found earlier stands until the password changes (it outranks the quick rules, which are asked
            // afresh each time, so a changed policy shows at the next sign-in).
            $reason = $user->password_weak_reason === self::BREACHED ? self::BREACHED : $this->quickReason($user, $password);
            $breachCheckDue = ! $reason && ! $user->password_checked_at && config('auth.password_policy.uncompromised');

            // Keyed on the password it checked, as the late write is: a change meanwhile isn't overwritten.
            User::query()->whereKey($user->id)->where('password', $user->password)->update(['password_weak_reason' => $reason]);

            if ($breachCheckDue) {
                $hash = $user->password;
                defer(fn () => $this->checkBreaches($user->id, $password, $hash));
            }
        } catch (Throwable $exception) {
            Log::warning('Could not check a password\'s strength at sign-in.', ['user_id' => $user->id, 'error' => class_basename($exception)]);
        }
    }

    private function quickReason(User $user, string $password): ?string
    {
        $basics = Validator::make(['password' => $password], ['password' => [PasswordRules::basics()]]);

        if ($basics->fails()) {
            return self::SHORT_OR_SIMPLE;
        }

        $identity = Validator::make(['password' => $password], ['password' => [new PasswordDoesNotContainIdentity($user)]]);

        return $identity->fails() ? self::CONTAINS_IDENTITY : null;
    }

    // $hash: the stored password it was signed in with -- written only if it's still that one (changed meanwhile, the
    // result is about an old password). Not marked checked when the service didn't answer: the next sign-in tries again.
    private function checkBreaches(int $userId, #[\SensitiveParameter] string $password, ?string $hash): void
    {
        try {
            $verifier = app(UncompromisedVerifier::class);
            $breached = $verifier instanceof PwnedPasswordVerifier
                ? $verifier->isBreached($password)
                : ! $verifier->verify(['value' => $password, 'threshold' => 0]);

            if ($breached === null) {
                return;
            }

            User::query()->whereKey($userId)->where('password', $hash)->update([
                'password_checked_at' => now(),
                ...($breached ? ['password_weak_reason' => self::BREACHED] : []),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Could not check a password against the breached list at sign-in.', ['user_id' => $userId, 'error' => class_basename($exception)]);
        }
    }
}
