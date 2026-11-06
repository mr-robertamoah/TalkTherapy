<?php

namespace App\Actions\CounsellorVerification;

use App\Actions\Action;
use App\Actions\Admin\GetAdminToDoCountsAction;
use App\Enums\CounsellorVerificationEventEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\User;
use App\Notifications\CounsellorVerificationRevokedNotification;
use App\Support\BestEffort;
use Illuminate\Support\Facades\DB;

// SCRUM-31: an administrator revokes a counsellor's verification. The same effect as it ending (the
// user's decision): no new work, current clients carry on; a suspension is the tool for stopping
// everything. Their first verified date stays; the revocation is recorded with its reason. A
// verification request of theirs still waiting is closed, so an older submission can't be accepted
// without its reviewer seeing the revocation -- they ask again.
class RevokeCounsellorVerificationAction extends Action
{
    public const REQUEST_CLOSED_REASON = 'Closed when the verification was revoked. Ask to be verified again.';

    public function execute(User $actor, Counsellor $counsellor, mixed $reason): Counsellor
    {
        $counsellor = DB::transaction(function () use ($actor, $counsellor, $reason) {
            // Requests first, then the counsellor: the order accepting one takes (the request, then
            // ApplyCounsellorVerificationAction's counsellor lock), so the two can't deadlock.
            $waiting = Request::query()
                ->whereFrom($counsellor)
                ->where('type', RequestTypeEnum::counsellor->value)
                ->wherePending()
                ->lockForUpdate()
                ->get();
            $counsellor = Counsellor::query()->lockForUpdate()->find($counsellor->id);
            $reason = EnsureCanRevokeCounsellorVerificationAction::new()->execute($actor, $counsellor, $reason);

            $counsellor->forceFill(['verification_revoked_at' => now()])->save();

            RecordCounsellorVerificationEventAction::new()->execute(
                $counsellor,
                CounsellorVerificationEventEnum::revoked,
                $actor,
                reason: $reason,
                expiresAt: $counsellor->verification_expires_at,
            );

            // Saved one by one: the Request saved hook clears the admin counts.
            $waiting
                ->each(fn (Request $request) => $request->forceFill([
                    'status' => RequestStatusEnum::inconsequencial->value,
                    'data' => [...($request->data ?? []), 'reason' => self::REQUEST_CLOSED_REASON],
                ])->save());

            // The renewals count no longer includes them.
            GetAdminToDoCountsAction::forgetAfterCommit(['revoked_counsellor' => $counsellor->id]);

            return [$counsellor, $reason];
        });

        [$counsellor, $reason] = $counsellor;

        BestEffort::attempt(fn () => $counsellor->notify(new CounsellorVerificationRevokedNotification($reason)), 'counsellor-verification-revoked', ['counsellor' => $counsellor->id]);

        return $counsellor;
    }
}
