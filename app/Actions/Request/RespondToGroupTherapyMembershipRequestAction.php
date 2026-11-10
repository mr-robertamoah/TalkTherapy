<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\GroupTherapy\AddGroupTherapyMemberAction;
use App\Actions\Guardianship\EnsureMinorHasActiveGuardianAction;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\CannotJoinGroupTherapyException;
use App\Exceptions\GuardianRequiredException;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Notifications\GroupTherapyMemberJoinedGuardianNotification;
use App\Notifications\GroupTherapyMembershipRequestAcceptedNotification;
use App\Notifications\GroupTherapyMembershipRequestRejectedNotification;
use Illuminate\Support\Facades\DB;

class RespondToGroupTherapyMembershipRequestAction extends Action
{
    // The requester is told why an accepted request couldn't be met.
    private const CLOSED_BECAUSE = [
        AddGroupTherapyMemberAction::ENDED => 'This group therapy has since ended.',
        AddGroupTherapyMemberAction::FULL => 'This group therapy has since reached its maximum number of members.',
    ];

    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $request = $requestResponseDTO->request;
        $responder = $requestResponseDTO->user;

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
        [$request, $groupTherapy, $outcome] = DB::transaction(function () use ($request, $response, $responder) {
            $groupTherapy = GroupTherapy::query()->lockForUpdate()->findOrFail($request->for_id);
            $request = $request->fresh();

            if ($request->status != RequestStatusEnum::pending->value) {
                return [$request, $groupTherapy, null];
            }

            // SCRUM-73: the add's own checks, under the lock. An ended or full group closes the
            // request; someone already taking part (an immediate join or an invitation link between
            // the request and this accept) leaves nothing to add.
            $closedBecause = null;
            $alreadyMember = false;

            if ($response == RequestStatusEnum::accepted->value) {
                try {
                    AddGroupTherapyMemberAction::new()->ensureCanAdd($groupTherapy, $request->from);
                } catch (CannotJoinGroupTherapyException $exception) {
                    $alreadyMember = $exception->getMessage() === AddGroupTherapyMemberAction::ALREADY_MEMBER;
                    $closedBecause = $alreadyMember ? null : (self::CLOSED_BECAUSE[$exception->getMessage()] ?? $exception->getMessage());
                }
            }

            if ($closedBecause) {
                $request->update([
                    'status' => RequestStatusEnum::rejected->value,
                    'data' => array_merge($request->data ?? [], ['reason' => $closedBecause]),
                ]);

                return [$request->refresh(), $groupTherapy, 'rejected'];
            }

            // SCRUM-288: re-read under the lock -- the requester may have lost their last guardian
            // since asking. Told neutrally: the group's counsellors aren't told why (the requester is
            // not their client). The responder (a group's creator can be a minor) is checked too.
            if ($response == RequestStatusEnum::accepted->value) {
                if ($request->from instanceof User && $request->from->isPausedForMissingGuardian(fresh: true)) {
                    throw GuardianRequiredException::requestUnavailable();
                }

                EnsureMinorHasActiveGuardianAction::new()->execute($responder, $groupTherapy, counterparty: false);
            }

            $request->update(['status' => $response]);

            // Already a member through some other path: the request is still marked accepted above,
            // with nothing left to add. SCRUM-73: a former member rejoins through the shared step,
            // which also tells a minor's guardians once this commits.
            if ($response == RequestStatusEnum::accepted->value) {
                if (! $alreadyMember) {
                    AddGroupTherapyMemberAction::new()->execute(
                        $groupTherapy,
                        $request->from,
                        (bool) ($request->data['anonymous'] ?? false),
                        GroupTherapyMemberJoinedGuardianNotification::VIA_REQUEST,
                    );
                }

                return [$request->refresh(), $groupTherapy, 'accepted'];
            }

            return [$request->refresh(), $groupTherapy, 'rejected'];
        });

        if ($outcome == 'accepted') {
            $request->from->notify(new GroupTherapyMembershipRequestAcceptedNotification($request));
        }

        if ($outcome == 'rejected') {
            $request->from->notify(new GroupTherapyMembershipRequestRejectedNotification($request));
        }

        return $request;
    }
}
