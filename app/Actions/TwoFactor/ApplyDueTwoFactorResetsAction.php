<?php

namespace App\Actions\TwoFactor;

use App\Actions\Action;
use App\Exceptions\TwoFactorException;
use App\Models\TwoFactorReset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-424: the scheduler (every minute) applies the resets whose wait is over -- and whose warning email went out
// (ops/security reviews: with the queue or mail down it waits, never applying unannounced) -- through
// ResetTwoFactorAction, the same reset as before the wait existed. Each one on its own, so one failure doesn't hold up
// the rest. Checked again under the lock: cancelled meanwhile, two-factor already off, or set up again since it was
// asked (a new phone) -- then it's closed, quietly, without applying.
class ApplyDueTwoFactorResetsAction extends Action
{
    public function execute(): int
    {
        $applied = 0;

        $ids = TwoFactorReset::query()->pending()->where('due_at', '<=', now())
            ->where(fn ($query) => $query->where('mail_required', false)->orWhereNotNull('notified_at'))
            ->orderBy('due_at')->limit(100)->pluck('id');

        foreach ($ids as $id) {
            try {
                $applied += $this->applyOne($id) ? 1 : 0;
            } catch (Throwable $exception) {
                Log::error('A two-factor reset could not be applied', ['reset_id' => $id, 'error' => $exception->getMessage()]);
            }
        }

        $unannounced = TwoFactorReset::query()->pending()->where('due_at', '<=', now())->where('mail_required', true)->whereNull('notified_at')->count();

        if ($unannounced > 0) {
            Log::warning('Two-factor resets are due but the person has not been emailed yet; they wait', ['count' => $unannounced]);
        }

        return $applied;
    }

    // True when it applied.
    public function applyOne(string $id): bool
    {
        [$reset, $closedAs] = DB::transaction(function () use ($id) {
            $found = TwoFactorReset::query()->find($id);
            $user = $found ? User::query()->whereKey($found->user_id)->lockForUpdate()->first() : null;
            $reset = $found ? TwoFactorReset::query()->whereKey($id)->lockForUpdate()->first() : null;

            if (! $user || ! $reset?->isPending() || $reset->due_at->isFuture() || ($reset->mail_required && ! $reset->notified_at)) {
                return [null, null];
            }

            $closedAs = match (true) {
                ! $user->hasTwoFactorEnabled() => TwoFactorReset::ALREADY_OFF,
                ! $user->two_factor_confirmed_at?->equalTo($reset->two_factor_confirmed_at_snapshot) => TwoFactorReset::SUPERSEDED,
                default => null,
            };

            if ($closedAs === null) {
                try {
                    ResetTwoFactorAction::new()->execute($user);
                } catch (TwoFactorException) {
                    $closedAs = TwoFactorReset::ALREADY_OFF;
                }
            }

            // Decided and written under the same lock. Closed ones are quiet: it simply no longer applies.
            $reset->forceFill($closedAs === null ? ['applied_at' => now()] : ['cancelled_at' => now(), 'cancel_reason' => $closedAs])->save();

            return [$reset, $closedAs];
        });

        if (! $reset) {
            return false;
        }

        Log::notice($closedAs === null ? 'Two-factor sign-in reset applied' : 'Two-factor sign-in reset cancelled', [
            'reset_id' => $reset->id, 'user_id' => $reset->user_id, 'requested_by' => $reset->requested_by_user_id, 'source' => $reset->source, 'reason' => $closedAs,
        ]);

        return $closedAs === null;
    }
}
