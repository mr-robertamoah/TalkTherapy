<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\GroupTherapy;
use App\Notifications\GroupTherapyMembershipRequestAcceptedGuardianNotification;
use App\Notifications\GroupTherapyMembershipRequestAcceptedNotification;
use App\Notifications\GroupTherapyMembershipRequestRejectedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RespondToGroupTherapyMembershipRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $request = $requestResponseDTO->request;

        $response = is_null($requestResponseDTO->response)
            ? RequestStatusEnum::rejected->value
            : strtoupper($requestResponseDTO->response);

        // Lock the group therapy row for the duration of this decision -- serializes
        // concurrent accept/join attempts against the same group's capacity. The request's
        // own status re-check just below is only safe *because* it happens inside this same
        // lock: two concurrent responses to the same request both go through this lock first,
        // so the second one always observes the first's committed status update rather than a
        // stale "still pending" read, and just no-ops instead of double-attaching (SCRUM-80).
        //
        // Notifications are deliberately fired AFTER the transaction commits, not inside it --
        // sending them mid-transaction risks notifying about a change that then rolls back, and
        // needlessly extends how long the row lock is held.
        [$request, $groupTherapy, $outcome] = DB::transaction(function () use ($request, $response) {
            $groupTherapy = GroupTherapy::query()->lockForUpdate()->findOrFail($request->for_id);
            $request = $request->fresh();

            if ($request->status != RequestStatusEnum::pending->value) {
                return [$request, $groupTherapy, null];
            }

            if ($response == RequestStatusEnum::accepted->value && $groupTherapy->users()->count() >= $groupTherapy->max_users) {
                $request->update([
                    'status' => RequestStatusEnum::rejected->value,
                    'data' => array_merge($request->data ?? [], [
                        'reason' => 'This group therapy has since reached its maximum number of members.',
                    ]),
                ]);

                return [$request->refresh(), $groupTherapy, 'rejected'];
            }

            $request->update(['status' => $response]);

            if ($response == RequestStatusEnum::accepted->value) {
                try {
                    // TT-3.2f-b/SCRUM-319: was_minor_at_join snapshot, same reasoning as
                    // JoinGroupTherapyAction's own identical attach call.
                    $groupTherapy->users()->attach($request->from->id, [
                        'anonymous' => $groupTherapy->resolveMembershipAnonymity((bool) ($request->data['anonymous'] ?? false)),
                        'was_minor_at_join' => ! $request->from->isAdult(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Already a member via some other path (e.g. an immediate join that
                    // happened between request-send and this accept) -- the request is still
                    // correctly marked accepted above, just nothing left to attach.
                }

                return [$request->refresh(), $groupTherapy, 'accepted'];
            }

            return [$request->refresh(), $groupTherapy, 'rejected'];
        });

        if ($outcome == 'accepted') {
            $request->from->notify(new GroupTherapyMembershipRequestAcceptedNotification($request));

            // TT-4.10b/SCRUM-291: deliberately does NOT pass 'for' => $groupTherapy here --
            // $request->from is the JOINING MEMBER, not the group's own addedby/owner, so
            // $groupTherapy->client_was_minor_at_creation answers a question about a different
            // person entirely (whoever created the group). TT-3.2f-b/SCRUM-319 later added a
            // per-membership snapshot (group_therapy_user.was_minor_at_join) for exactly this
            // question, but AlertGuardianAction's own live-isAdult() check here predates and is
            // independent of that -- this alert fires at the moment of acceptance, when
            // $request->from's live status IS the accurate, current answer, so there's no
            // bypass risk the snapshot exists to close elsewhere (GroupTherapy::memberIsMinor(),
            // used by the video speaking-permission grant path).
            AlertGuardianAction::new()->execute(
                GuardianAlertDTO::new()->fromArray([
                    'user' => $request->from,
                    'notification' => new GroupTherapyMembershipRequestAcceptedGuardianNotification($groupTherapy),
                ])
            );
        }

        if ($outcome == 'rejected') {
            $request->from->notify(new GroupTherapyMembershipRequestRejectedNotification($request));
        }

        return $request;
    }
}
