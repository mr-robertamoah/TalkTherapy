<?php

namespace App\Actions\Suspension;

use App\Actions\Action;
use App\Enums\SuspensionScopeEnum;
use App\Events\AccountSuspendedEvent;
use App\Exceptions\SuspensionException;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\SuspensionAppliedNotification;
use App\Notifications\WardSuspendedNotification;
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

        $suspension = DB::transaction(function () use ($admin, $report, $scope, $days, $publicReason, $internalNote) {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            $target = EnsureCanApplySuspensionAction::new()->execute($admin, $report, $scope);
            User::query()->whereKey($target->id)->lockForUpdate()->first();

            // A locking read: under REPEATABLE READ a plain one could still see the snapshot from
            // before another admin's suspension of the same person committed.
            $already = Suspension::query()->where('user_id', $target->id)->active()->covering($scope)->lockForUpdate()->exists();
            if ($already) {
                throw new SuspensionException('This person is already suspended.', 422);
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

            ApplySuspensionEffectsAction::new()->execute($suspension->setRelation('user', $target->forgetActiveSuspension()));

            return $suspension;
        });

        $this->notify($suspension);

        return $suspension;
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
