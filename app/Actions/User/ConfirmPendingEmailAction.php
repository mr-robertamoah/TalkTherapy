<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-427: the new address's owner confirmed it (the button on the link's page -- opening the link changes nothing).
// Only for the address the link was made for (a later request makes earlier links useless), only if no other account
// took it meanwhile. When the current address is verified it then waits auth.email_change_wait_hours (security review:
// an attacker confirms their own address in seconds) -- the current address is told when, with a "This wasn't me"
// link; otherwise it takes effect at once.
class ConfirmPendingEmailAction extends Action
{
    public const CONFIRMED = 'confirmed';

    public const WAITING = 'waiting';

    public const INVALID = 'invalid';

    public const TAKEN = 'taken';

    public function execute(int $userId, string $hash): string
    {
        try {
            return $this->confirm($userId, $hash);
        } catch (UniqueConstraintViolationException) {
            // Another account took the address between the check and the write (ops review): the same answer as
            // "taken" -- clearing only that request, never one asked for since.
            User::query()->whereKey($userId)->get()
                ->filter(fn (User $user) => $user->pending_email && hash_equals((string) $user->pendingEmailHash(), $hash))
                ->each->clearPendingEmail();

            return self::TAKEN;
        }
    }

    private function confirm(int $userId, string $hash): string
    {
        return DB::transaction(function () use ($userId, $hash) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if (! $user?->pending_email || $user->pending_email_confirmed_at || ! hash_equals((string) $user->pendingEmailHash(), $hash)) {
                return self::INVALID;
            }

            if (User::query()->whereKeyNot($user->id)->where('email', $user->pending_email)->exists()) {
                $user->clearPendingEmail();

                return self::TAKEN;
            }

            // Nothing to protect (no address ever verified): at once. Else it waits, and that address is told -- unless
            // another account has it now (SCRUM-431: it still waits).
            if (! $user->protectedEmail()) {
                ApplyEmailChangeAction::new()->execute($user);

                return self::CONFIRMED;
            }

            $user->forceFill(['pending_email_confirmed_at' => now()])->save();

            EmailChangedNotification::sendTo($user->protectedEmailRecipient(), $user->name ?: $user->username, $user->pending_email,
                byTeam: $user->pending_email_requested_by !== null, stage: EmailChangedNotification::CONFIRMED,
                userId: $user->id, effectiveAt: $user->pendingEmailEffectiveAt()->format('j F Y, H:i T'),
                pendingHash: $user->pendingEmailHash(), accountHasEmail: (bool) $user->email, stale: $user->protectedEmailIsStale());

            Log::notice('Account email change confirmed, waiting', ['user_id' => $user->id]);

            return self::WAITING;
        });
    }
}
