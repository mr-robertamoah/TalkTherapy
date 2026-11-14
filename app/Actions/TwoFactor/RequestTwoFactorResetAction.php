<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\TwoFactorReset;
use App\Models\User;
use App\Notifications\TwoFactorResetRequestedNotification;
use Illuminate\Support\Facades\DB;

// SCRUM-424: schedules a reset of someone's two-factor sign-in by the team (the admin users list, or the server
// command) -- it applies after auth.two_factor.reset_wait_hours (ApplyDueTwoFactorResetsAction) unless cancelled
// meanwhile. The person is told at once, with a cancel link when their email is verified. Who may ask is the caller's
// check; who asked is the caller's log line.
class RequestTwoFactorResetAction extends Action
{
    public function execute(User $user, ?User $requestedBy, string $source, ?string $reason = null): TwoFactorReset
    {
        return DB::transaction(function () use ($user, $requestedBy, $source, $reason) {
            // The user row first, as every reset step does: one lock order.
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasTwoFactorEnabled()) {
                throw new TwoFactorException('This account doesn\'t have two-factor sign-in on.', 422);
            }

            if ($pending = $locked->pendingTwoFactorReset()->first()) {
                throw new TwoFactorException("A reset is already scheduled for {$pending->due_at->format('j F Y, H:i T')}.", 422);
            }

            $reset = TwoFactorReset::query()->create([
                'user_id' => $locked->id,
                'requested_by_user_id' => $requestedBy?->id,
                'source' => $source,
                'reason' => filled($reason) ? mb_substr(trim($reason), 0, 255) : null,
                'two_factor_confirmed_at_snapshot' => $locked->two_factor_confirmed_at,
                'due_at' => now()->addHours((int) config('auth.two_factor.reset_wait_hours')),
                // Ops review: it only applies once this email has gone out (TwoFactorResetRequestedNotification).
                'mail_required' => (bool) ($locked->email && $locked->email_verified_at),
            ]);

            $locked->notify(TwoFactorResetRequestedNotification::for($reset));

            return $reset;
        });
    }
}
