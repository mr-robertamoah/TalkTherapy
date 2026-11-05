<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\SafeguardingException\EnsureCanManageSafeguardingExceptionAction;
use App\Actions\SafeguardingException\GrantSafeguardingExceptionAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\SafeguardingExceptionRefusedException;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\User;
use App\Notifications\SafeguardingExceptionRequestDecidedNotification;
use App\Support\BestEffort;
use Illuminate\Support\Facades\DB;

// SCRUM-381: the super administrator answers a counsellor's request for a safeguarding exception
// (EnsureUserCanRespondToRequestAction has already checked they may). Accepting grants one with the
// administrator's own end date and reason ($endsAt, $reason) and links it to the request
// (GrantSafeguardingExceptionAction, which refuses with 422 when the minor is no longer paused).
// Rejecting (by the same super administrator rule) just closes it. Either way the counsellor is told the outcome, neutrally.
class RespondToSafeguardingExceptionRequestAction extends Action
{
    public function execute(RequestResponseDTO $dto): Request
    {
        $request = $dto->request;
        $wardId = (int) $request->safeguardingWardId();
        $endsAt = null;
        $noLongerNeeded = false;

        if (strtoupper((string) $dto->response) === RequestStatusEnum::accepted->value) {
            $exception = GrantSafeguardingExceptionAction::new()->execute($dto->user, User::query()->find($wardId), $dto->reason, $dto->endsAt, $request);
            $endsAt = $exception->ends_at;
            $request = $request->refresh();
        } else {
            // The same check as accepting (a super administrator who doesn't counsel the minor) --
            // except that a request whose minor's row is gone for good can still be closed.
            $ward = User::withTrashed()->find($wardId);
            EnsureCanManageSafeguardingExceptionAction::new()->ensureCanClose($dto->user, $ward);
            // Rejected from the "no longer needed" list: the counsellor is told it was closed, not
            // that the therapy stays paused.
            $noLongerNeeded = ! $ward || $ward->trashed() || ! $ward->isPausedForMissingGuardian(fresh: true);

            $request = DB::transaction(function () use ($request, $wardId) {
                User::query()->whereKey($wardId)->lockForUpdate()->first(['id']);
                $locked = Request::query()->lockForUpdate()->findOrFail($request->id);

                // Answered (or closed by a grant) since it was listed: refused like a late accept,
                // and nobody is told again.
                if ($locked->status !== RequestStatusEnum::pending->value) {
                    throw new SafeguardingExceptionRefusedException(GrantSafeguardingExceptionAction::ALREADY_ANSWERED, 422);
                }

                $locked->update(['status' => RequestStatusEnum::rejected->value]);

                return $locked;
            });
        }

        $counsellor = $request->from;
        if ($counsellor instanceof Counsellor && $counsellor->user) {
            BestEffort::attempt(
                fn () => $counsellor->user->notify(new SafeguardingExceptionRequestDecidedNotification($request, $endsAt, closed: $noLongerNeeded)),
                'safeguarding-exception-decided-notify',
                ['request' => $request->id],
            );
        }

        return $request;
    }
}
