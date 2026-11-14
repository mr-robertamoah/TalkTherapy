<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-427: the scheduler (every minute) applies confirmed new emails whose wait is over -- each on its own, under its
// row lock, checked again (still waiting, still not taken by another account).
class ApplyDueEmailChangesAction extends Action
{
    public function execute(): int
    {
        $applied = 0;
        $cutoff = now()->subHours((int) config('auth.email_change_wait_hours'));

        $ids = User::query()->whereNotNull('pending_email')->whereNotNull('pending_email_confirmed_at')
            ->where('pending_email_confirmed_at', '<=', $cutoff)->limit(100)->pluck('id');

        foreach ($ids as $id) {
            try {
                $applied += DB::transaction(function () use ($id, $cutoff) {
                    $user = User::query()->whereKey($id)->lockForUpdate()->first();

                    if (! $user?->pending_email || ! $user->pending_email_confirmed_at || $user->pending_email_confirmed_at->gt($cutoff)) {
                        return 0;
                    }

                    if (User::query()->whereKeyNot($user->id)->where('email', $user->pending_email)->exists()) {
                        $user->clearPendingEmail();

                        return 0;
                    }

                    ApplyEmailChangeAction::new()->execute($user);

                    return 1;
                });
            } catch (Throwable $exception) {
                Log::error('An email change could not be applied', ['user_id' => $id, 'error' => $exception->getMessage()]);
            }
        }

        return $applied;
    }
}
