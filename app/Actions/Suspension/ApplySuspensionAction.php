<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Actions\Guardianship\RemoveGuardianFromWardAction;
use App\Enums\SuspensionScopeEnum;
use App\Events\AccountSuspendedEvent;
use App\Events\SessionUpdatedEvent;
use App\Exceptions\SuspensionException;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\SuspensionAppliedNotification;
use App\Notifications\WardSuspendedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-359: suspends the person a resolved report is about. Everything -- the checks, the
// suspension, its history row and its effects on their clients (ApplySuspensionEffectsAction) --
// happens in one transaction on the locked report and person, so two admins can't suspend the
// same person twice. Notifications (afterCommit) and the live sign-out go out only after it commits.
class ApplySuspensionAction extends Action
{
    public const DURATIONS = [7, 30, 90];

    public function execute(
        User $admin,
        ?Report $report,
        SuspensionScopeEnum $scope,
        ?int $days,
        string $publicReason,
        ?string $internalNote = null,
    ): Suspension {
        EnsureCanApplySuspensionAction::new()->execute($admin, $report, $scope);

        if (! is_null($days) && ! in_array($days, self::DURATIONS, true)) {
            throw new SuspensionException('Choose 7, 30 or 90 days, or until lifted.', 422);
        }

        $publicReason = trim($publicReason);
        if (mb_strlen($publicReason) < 10) {
            throw new SuspensionException('Write the reason the person will see (at least 10 characters).', 422);
        }

        [$suspension, $endedSessions] = DB::transaction(function () use ($admin, $report, $scope, $days, $publicReason, $internalNote) {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            $target = EnsureCanApplySuspensionAction::new()->execute($admin, $report, $scope);
            User::query()->whereKey($target->id)->lockForUpdate()->first();

            // A locking read: under REPEATABLE READ a plain one could still see the snapshot from
            // before another admin's suspension of the same person committed.
            $already = Suspension::query()->where('user_id', $target->id)->active()->covering($scope)->lockForUpdate()->exists();
            if ($already) {
                throw new SuspensionException('This person is already suspended.', 422);
            }

            // SCRUM-288 (Q3): a guardian under an account suspension doesn't count -- each ward this
            // leaves with no active guardian is paused once it commits (and resumed when it ends).
            if ($scope == SuspensionScopeEnum::account) {
                RemoveGuardianFromWardAction::new()->forAllWardsOf($target, RemoveGuardianFromWardAction::GUARDIAN_SUSPENDED);
            }

            $suspension = Suspension::create([
                'user_id' => $target->id,
                'report_id' => $report->id,
                'scope' => $scope,
                'starts_at' => now(),
                'ends_at' => $days ? now()->addDays($days) : null,
                'public_reason' => $publicReason,
                'internal_note' => trim((string) $internalNote) ?: null,
                'created_by' => $admin->id,
            ]);

            ReportStatusHistory::create([
                'report_id' => $report->id,
                'user_id' => $admin->id,
                'event' => ReportStatusHistory::EVENT_SUSPENSION_APPLIED,
                'note' => $suspension->internal_note,
                'data' => [
                    'suspension' => $suspension->id,
                    'scope' => $scope->value,
                    'endsAt' => $suspension->ends_at?->toJSON(),
                ],
            ]);

            $endedSessions = ApplySuspensionEffectsAction::new()->execute($suspension->setRelation('user', $target->forgetActiveSuspension()));

            return [$suspension, $endedSessions];
        });

        $this->notify($suspension);
        $this->afterEffects($suspension, $endedSessions);

        return $suspension;
    }

    // SCRUM-363: everything that reaches outside the database, once committed and after the person
    // has been told -- each step best effort, so none can undo or block the others.
    private function afterEffects(Suspension $suspension, Collection $endedSessions): void
    {
        // Open pages see the cancelled sessions change without reloading.
        $endedSessions->each(fn (Session $session) => rescue(fn () => SessionUpdatedEvent::dispatch($session), report: true));

        // Provider calls never run inside the locked transaction.
        rescue(fn () => RemoveSuspendedPersonFromVideoAction::new()->execute($suspension, $endedSessions), report: true);
    }

    private function notify(Suspension $suspension): void
    {
        $person = $suspension->user;

        Notification::send($person, new SuspensionAppliedNotification($suspension));

        if (! $person->isAdult()) {
            $person->guardians()->with('guardian')->get()
                ->pluck('guardian')
                ->filter()
                ->each(fn (User $guardian) => Notification::send($guardian, new WardSuspendedNotification($suspension)));
        }

        // Convenience only (the middleware enforces it), so Reverb being down must not fail the
        // request after the suspension has been committed.
        if ($suspension->scope == SuspensionScopeEnum::account) {
            rescue(fn () => AccountSuspendedEvent::dispatch($person->id), report: true);
        }
    }
}
