<?php

namespace App\Actions\SafeguardingException;

use App\Actions\Action;
use App\Actions\Guardianship\AnnounceGuardianAccessChangeAction;
use App\Actions\Guardianship\GetWardOpenTherapiesAction;
use App\Actions\Guardianship\HandleGuardianRestoredAction;
use App\Enums\RequestStatusEnum;
use App\Exceptions\SafeguardingExceptionRefusedException;
use App\Models\Request;
use App\Models\SafeguardingException;
use App\Models\User;
use App\Notifications\TherapyContinuationNotification;
use App\Support\BestEffort;
use Illuminate\Support\Facades\Log;

// SCRUM-381: a super administrator lets a minor with no active guardian carry on in therapy until a
// date (at most 30 days away), with a reason -- or renews the one in force (that row is ended as
// `renewed`, and the new one points back at it). $request: the counsellor's request being approved
// (RespondToSafeguardingExceptionRequestAction); then the minor must still be paused, so it is never
// a renewal.
//
// It runs inside HandleGuardianRestoredAction::around(), which locks the minor's row and, when they
// were paused, announces the resume once committed -- neutrally, with the end date
// ($continuesUntil), and only for what nothing else still blocks. A renewal tells the same people
// the new end date. The lock order: the minor's row, then their exception rows, then request rows.
class GrantSafeguardingExceptionAction extends Action
{
    public const NOT_NEEDED = 'This person isn\'t paused for a missing guardian, so no safeguarding exception is needed.';

    public const NO_LONGER_NEEDED = 'This request is no longer needed: the client\'s therapy isn\'t paused any more.';

    public const ALREADY_ANSWERED = 'This request has already been answered.';

    public function execute(?User $admin, ?User $ward, ?string $reason, mixed $endsAt, ?Request $request = null): SafeguardingException
    {
        EnsureCanManageSafeguardingExceptionAction::new()->execute($admin, $ward);
        [$reason, $endsAt] = EnsureSafeguardingExceptionTermsAction::new()->execute($reason, $endsAt);

        $previous = null;

        $exception = HandleGuardianRestoredAction::new()->around(
            $ward,
            HandleGuardianRestoredAction::SAFEGUARDING_EXCEPTION_GRANTED,
            function () use ($admin, $ward, $reason, $endsAt, $request, &$previous) {
                [$locked, $previous] = SafeguardingException::lockActiveWithWard($ward->id);

                if (! $locked?->lacksGuardianCover(fresh: true)) {
                    throw new SafeguardingExceptionRefusedException($request ? self::NO_LONGER_NEEDED : self::NOT_NEEDED, 422);
                }

                $answering = null;
                if ($request) {
                    if ($previous) {
                        throw new SafeguardingExceptionRefusedException(self::NO_LONGER_NEEDED, 422);
                    }

                    $answering = Request::query()->lockForUpdate()->find($request->id);

                    if (! $answering || $answering->status !== RequestStatusEnum::pending->value) {
                        throw new SafeguardingExceptionRefusedException(self::ALREADY_ANSWERED, 422);
                    }
                }

                $exception = SafeguardingException::query()->create([
                    'ward_id' => $locked->id,
                    'granted_by' => $admin->id,
                    'request_id' => $answering?->id,
                    'reason' => $reason,
                    'starts_at' => now(),
                    'ends_at' => $endsAt,
                    'previous_id' => $previous?->id,
                ]);

                $previous?->forceFill([
                    'ended_at' => now(),
                    'ended_by' => $admin->id,
                    'end_kind' => SafeguardingException::END_RENEWED,
                    'end_processed_at' => now(),
                ])->save();

                $answering?->update(['status' => RequestStatusEnum::accepted->value]);

                // Any other counsellor's request for this minor is moot now: closed, not rejected
                // (the minor's counsellors are told the therapy carries on either way). Found without a
                // lock (data.wardId has no index, so a locking read would lock every row it scans --
                // the whole table); the minor's row lock already serialises everyone who writes these.
                $others = Request::query()
                    ->pendingSafeguardingException($locked)
                    ->when($answering, fn ($query) => $query->whereKeyNot($answering->id))
                    ->pluck('id');

                if ($others->isNotEmpty()) {
                    Request::query()->whereKey($others)->wherePending()->lockForUpdate()->get()
                        ->each(fn (Request $other) => $other->update(['status' => RequestStatusEnum::inconsequencial->value]));
                }

                return $exception;
            },
            $endsAt,
        );

        // A caller's instance memoises whether one is in force (User::hasActiveSafeguardingException()).
        $ward->forgetSafeguardingException();

        if ($previous) {
            $this->announceRenewal($ward, $exception);
        }

        Log::info('safeguarding_exception.granted', [
            'exception' => $exception->id,
            'ward' => $exception->ward_id,
            'by' => $admin->id,
            'request' => $exception->request_id,
            'renews' => $previous?->id,
        ]);

        return $exception;
    }

    private function announceRenewal(User $ward, SafeguardingException $exception): void
    {
        BestEffort::attempt(function () use ($ward, $exception) {
            $restored = HandleGuardianRestoredAction::new();
            $open = $restored->carryingOn($ward);

            if (GetWardOpenTherapiesAction::new()->hasAny($open)) {
                AnnounceGuardianAccessChangeAction::new()->continuation($ward, $open, TherapyContinuationNotification::RENEWED, $exception->ends_at);
            }
        }, 'safeguarding-exception-renewal-notify', ['exception' => $exception->id]);
    }
}
