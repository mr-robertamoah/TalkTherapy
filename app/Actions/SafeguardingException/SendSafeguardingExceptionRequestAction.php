<?php

namespace App\Actions\SafeguardingException;

use App\Actions\Action;
use App\Actions\Request\CreateRequestAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\SafeguardingExceptionRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\SafeguardingExceptionRefusedException;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\SafeguardingExceptionRequestedNotification;
use App\Support\BestEffort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-381: the counsellor of a 1:1, or an active counsellor of a group, asks the super
// administrator for a safeguarding exception for a client or member who is paused for a missing
// guardian. Checked here: they are that therapy's counsellor and not suspended, it hasn't ended,
// the minor is paused right now (re-read under the minor's row lock) -- so no exception is in force
// -- and nobody has a request of this kind waiting for that minor in that therapy. The super
// administrators are told; the reason stays behind the admin page.
class SendSafeguardingExceptionRequestAction extends Action
{
    public const NOT_COUNSELLOR = 'Only this therapy\'s counsellor can ask for a safeguarding exception.';

    public const NOT_PAUSED = 'This client\'s therapy isn\'t paused, so no safeguarding exception is needed.';

    public const NOT_A_MEMBER = 'This person isn\'t a member of the group.';

    public const ALREADY_REQUESTED = 'A safeguarding exception has already been requested for this client. It is waiting for review.';

    public function execute(SafeguardingExceptionRequestDTO $dto): Request
    {
        $therapy = $dto->therapy;
        $counsellor = $dto->user?->counsellor;

        if (! $therapy) {
            throw new SafeguardingExceptionRefusedException('Therapy not found.', 404);
        }

        $isCounsellor = $counsellor && ($therapy instanceof GroupTherapy
            ? $therapy->isCounsellor($counsellor)
            : $therapy->counsellor_id === $counsellor->id);

        if (! $isCounsellor) {
            throw new SafeguardingExceptionRefusedException(self::NOT_COUNSELLOR, 403);
        }

        EnsureCanActAsCounsellorAction::new()->execute($counsellor);

        if ($therapy->status === TherapyStatusEnum::ended->value) {
            throw new SafeguardingExceptionRefusedException('This therapy has ended.', 422);
        }

        [$reason, $proposedEndsAt] = EnsureSafeguardingExceptionTermsAction::new()->execute($dto->reason, $dto->proposedEndsAt, 'the request');

        $ward = $therapy instanceof GroupTherapy
            ? GetPausedGroupMembersAction::new()->resolve($therapy, $dto->memberKey)
            : ($therapy->addedby_type === User::class ? $therapy->addedby : null);

        if (! $ward || $ward->is($dto->user)) {
            throw new SafeguardingExceptionRefusedException($therapy instanceof GroupTherapy ? self::NOT_A_MEMBER : self::NOT_PAUSED, 422);
        }

        $request = DB::transaction(function () use ($therapy, $counsellor, $ward, $reason, $proposedEndsAt) {
            $locked = User::query()->whereKey($ward->id)->lockForUpdate()->first();

            // SCRUM-73: still a member, re-read under the minor's row lock -- leaving locks the same
            // row, so a request racing a leave is refused rather than left pending for nobody.
            if ($locked && $therapy instanceof GroupTherapy && ! $therapy->isUser($locked) && ! $therapy->users()->whereKey($locked->id)->sharedLock()->exists()) {
                throw new SafeguardingExceptionRefusedException(self::NOT_A_MEMBER, 422);
            }

            // Paused means no exception is in force either (User::isPausedForMissingGuardian()).
            if (! $locked?->isPausedForMissingGuardian(fresh: true)) {
                throw new SafeguardingExceptionRefusedException(self::NOT_PAUSED, 422);
            }

            // No locking read: data.wardId has no index, so it would lock every row it scans. The
            // minor's row lock above already serialises everyone who writes these requests.
            $pending = Request::query()
                ->pendingSafeguardingException($locked, $therapy)
                ->exists();

            if ($pending) {
                throw new SafeguardingExceptionRefusedException(self::ALREADY_REQUESTED, 422);
            }

            return CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
                'from' => $counsellor,
                'to' => null,
                'for' => $therapy,
                'type' => RequestTypeEnum::safeguardingException->value,
                'data' => [
                    'reason' => $reason,
                    'proposedEndsAt' => $proposedEndsAt->toJSON(),
                    'wardId' => $locked->id,
                ],
            ]));
        });

        BestEffort::attempt(
            // Not a super administrator who counsels the minor: they can't decide it.
            fn () => Notification::send(EnsureCanManageSafeguardingExceptionAction::new()->deciders($ward), new SafeguardingExceptionRequestedNotification($request)),
            'safeguarding-exception-requested-notify',
            ['request' => $request->id],
        );

        return $request;
    }
}
