<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\CounsellorGroupTherapyRoleEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\CannotRespondToRequestException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\User;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use Illuminate\Support\Facades\DB;

// SCRUM-358: a group creator invites counsellors when they set up the group (one group-therapy
// assistance request each). Accepting one used to go through the individual-therapy action, which
// loads a Therapy and so always failed. Product decision (2026-11-01): accepting adds the counsellor
// as an active, normal-role counsellor -- unless the group is already full, which is refused with a
// clear message and leaves the invite pending. Once the group fills up, the remaining invites close.
class RespondToGroupTherapyAssistanceRequestAction extends Action
{
    public const GROUP_FULL = 'This group already has its maximum number of counsellors.';

    public const NOT_ALLOWED = 'This invitation can no longer be accepted.';

    public function execute(RequestResponseDTO $requestResponseDTO): Request
    {
        // Same lock order as RespondToTherapyAssistanceRequestAction: the group first (the shared
        // resource two simultaneous accepts compete for), then the request.
        [$request, $accepted] = DB::transaction(function () use ($requestResponseDTO) {
            $group = GroupTherapy::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->for_id);
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return [$request, false];
            }

            $response = is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response);

            if ($response != RequestStatusEnum::accepted->value) {
                $request->update(['status' => $response]);

                return [$request, false];
            }

            $counsellor = $request->to;
            $this->ensureCanJoin($group, $counsellor, $requestResponseDTO->user);

            $activeCounsellors = $group->activeCounsellors();
            $alreadyActive = $activeCounsellors->contains('id', $counsellor->id);

            if (! $alreadyActive && $activeCounsellors->count() >= $group->max_counsellors) {
                throw new CannotRespondToRequestException(self::GROUP_FULL, 422);
            }

            $existing = $group->counsellors()->whereKey($counsellor->id)->first();
            $group->counsellors()->syncWithoutDetaching([
                $counsellor->id => [
                    'state' => CounsellorGroupTherapyStateEnum::active->value,
                    'role' => $existing?->pivot->role ?? CounsellorGroupTherapyRoleEnum::normal->value,
                ],
            ]);

            $request->update(['status' => $response]);

            if ($group->activeCounsellors()->count() >= $group->max_counsellors) {
                Request::query()
                    ->whereNot('id', $request->id)
                    ->wherePending()
                    ->whereFor($group)
                    ->where('type', $request->type)
                    ->update([
                        'status' => RequestStatusEnum::inconsequencial->value,
                        'data' => ['reason' => 'This group has filled all its counsellor places.'],
                    ]);
            }

            return [$request->refresh(), true];
        });

        if ($accepted) {
            $request->from->notify(new TherapyAssistanceRequestAcceptedNotification($request));
        }

        return $request;
    }

    // Security review: accepting an invite now really adds a counsellor, so it must be someone who
    // may: the invited counsellor themselves (an admin can't accept on their behalf -- joining is
    // their consent), still a verified counsellor, not the group's own client or a member (who
    // would gain moderation powers over the other members), and a group that hasn't ended. One
    // answer for all of these.
    private function ensureCanJoin(GroupTherapy $group, ?Counsellor $counsellor, ?User $responder): void
    {
        $allowed = $counsellor
            && $counsellor->user
            && $responder && $counsellor->user->is($responder)
            && $counsellor->verified_at
            && $group->status !== TherapyStatusEnum::ended->value
            && ! $group->isUser($counsellor->user)
            && ! $group->users()->whereKey($counsellor->user->id)->exists();

        if (! $allowed) {
            throw new CannotRespondToRequestException(self::NOT_ALLOWED, 422);
        }
    }
}
