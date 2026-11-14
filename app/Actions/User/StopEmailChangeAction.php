<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// SCRUM-427 (security review): "This wasn't me", from the warning to the account's current address -- the change is
// dropped, every session of the account ends ("remember me" too), and the password stops working: whoever made the
// change knows it, and would only start again. The owner sets a new one with "Forgot your password?" (it goes to the
// address this link came to). Only for the change the link was sent about, and only before it has taken effect.
class StopEmailChangeAction extends Action
{
    public function execute(int $userId, string $hash): bool
    {
        $stopped = DB::transaction(function () use ($userId, $hash) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if (! $user?->pending_email || ! hash_equals((string) $user->pendingEmailHash(), $hash)) {
                return false;
            }

            // The email was removed and this address is the one protected: put it back (security review: else
            // "Forgot your password?" couldn't reach them) -- unless another account has taken it since.
            $restore = ! $user->email && $user->last_verified_email
                && ! User::query()->whereKeyNot($user->id)->where('email', $user->last_verified_email)->exists();

            $user->forceFill([
                ...($restore ? ['email' => $user->last_verified_email, 'email_verified_at' => now()] : []),
                'pending_email' => null,
                'pending_email_requested_at' => null,
                'pending_email_requested_by' => null,
                'pending_email_confirmed_at' => null,
                'session_version' => (int) $user->session_version + 1,
                'remember_token' => Str::random(60),
                'password' => Str::random(64),
            ])->save();

            return true;
        });

        if ($stopped) {
            Log::warning('Account email change stopped by its owner', ['user_id' => $userId]);
        }

        return $stopped;
    }
}
