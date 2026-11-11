<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\OrganizationException;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-417: the applicant takes back their own application to join an organisation, while it's still
// waiting. Closed as INCONSEQUENCIAL with a reason (as a request made moot is), so it leaves the admins'
// queue (pending only) and they can apply again. Locked like answering it
// (RespondToOrganizationMemberRequestAction), so it's either withdrawn or answered, never both.
class WithdrawOrganizationMemberApplicationAction extends Action
{
    public const WITHDRAWN = 'Withdrawn by the applicant.';

    public const NOT_FOUND = 'This application could not be found.';

    public const NOT_PENDING = 'This application has already been answered or withdrawn.';

    public function execute(User $user, mixed $requestId): Request
    {
        return DB::transaction(function () use ($user, $requestId) {
            // Someone else's, or not an application, is told the same as a missing one.
            $application = Request::query()
                ->whereKey($requestId)
                ->where('type', RequestTypeEnum::organizationMemberApplication->value)
                ->whereFrom($user)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw new OrganizationException(self::NOT_FOUND, 404);
            }

            if ($application->status !== RequestStatusEnum::pending->value) {
                throw new OrganizationException(self::NOT_PENDING, 409);
            }

            $application->update([
                'status' => RequestStatusEnum::inconsequencial->value,
                'data' => [...($application->data ?? []), 'reason' => self::WITHDRAWN, 'withdrawn' => true],
            ]);

            return $application->refresh();
        });
    }
}
