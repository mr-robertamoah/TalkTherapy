<?php

namespace App\Services;

use App\Actions\Admin\RecordAdminContentReadAction;
use App\Actions\Counsellor\EnsureCounsellorOfferCapAction;
use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\GroupTherapy\CreateGroupTherapyAction;
use App\Actions\GroupTherapy\EnsureCanSendGroupTherapyAssistanceRequestAction;
use App\Actions\GroupTherapy\EnsureCanSetGroupTherapyPaymentGateAction;
use App\Actions\GroupTherapy\EnsureGroupTherapyCanBecomeAnonymousAction;
use App\Actions\GroupTherapy\JoinGroupTherapyAction;
use App\Actions\GroupTherapy\LeaveGroupTherapyAction;
use App\Actions\GroupTherapy\PreloadGroupTherapyListAction;
use App\Actions\GroupTherapy\UpdateGroupTherapyAction;
use App\Actions\Guardianship\EnsureMinorHasActiveGuardianAction;
use App\Actions\Link\FindUsableGroupInvitationAction;
use App\Actions\Request\SendTherapyAssistanceRequestAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\Actions\Therapy\DeleteTherapyAction;
use App\Actions\Therapy\EndTherapyAction;
use App\Actions\Therapy\EnsureCanCreateTherapyAction;
use App\Actions\Therapy\EnsureCanEndTherapyAction;
use App\Actions\Therapy\EnsureCanUpdateTherapyAction;
use App\Actions\Therapy\EnsureTherapyDataIsValidAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\Actions\Therapy\EnsureUserHasAccessToTherapyAction;
use App\Actions\Therapy\ResolveTherapyCardStateAction;
use App\Actions\User\AlertGuardianAction;
use App\Actions\User\EnsureUserMeetsTherapyRequirementsAction;
use App\DTOs\CreateStarDTO;
use App\DTOs\GetTherapyDTO;
use App\DTOs\GroupTherapyDTO;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\JoinGroupTherapyDTO;
use App\DTOs\TherapyAssistanceRequestDTO;
use App\Enums\PaginationEnum;
use App\Enums\StarTypeEnum;
use App\Exceptions\TherapyException;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Link;
use App\Models\User;
use App\Notifications\TherapyCreatedNotification;
use App\Support\PreferenceMatch;
use App\Support\PreferenceRanking;
use App\Support\VideoJoinLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GroupTherapyService extends Service
{
    // max counsellors and users, allow anyone, shareequally and counsellors payment share
    public function createGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureUserMeetsTherapyRequirementsAction::new()->execute($groupTherapyDTO->user);

        EnsureCanCreateTherapyAction::new()->execute($groupTherapyDTO->user);

        EnsureTherapyDataIsValidAction::new()->execute($groupTherapyDTO);

        // SCRUM-359: a suspended counsellor can't start a group, or be asked to join a new one.
        EnsureCanActAsCounsellorAction::new()->execute($groupTherapyDTO->counsellor);
        // SCRUM-31: and only a currently verified counsellor starts one, or is asked to join it.
        EnsureCounsellorMayTakeOnClientsAction::new()->execute($groupTherapyDTO->counsellor);
        Counsellor::query()->whereKey($groupTherapyDTO->counsellorIds ?: [])->get()
            ->each(function (Counsellor $counsellor) {
                EnsureCanActAsCounsellorAction::new()->execute($counsellor, asTarget: true);
                EnsureCounsellorMayTakeOnClientsAction::new()->execute($counsellor, asTarget: true);
            });

        $therapy = CreateGroupTherapyAction::new()->execute($groupTherapyDTO);

        if ($groupTherapyDTO->counsellor) {
            CreateStarAction::new()->execute(
                CreateStarDTO::fromArray([
                    'starredby' => null,
                    'starred' => $groupTherapyDTO->user,
                    'starreable' => $therapy,
                    'type' => StarTypeEnum::participation->value,
                ])
            );
        }

        AlertGuardianAction::new()->execute(
            GuardianAlertDTO::new()->fromArray([
                'user' => $groupTherapyDTO->user,
                'notification' => new TherapyCreatedNotification($therapy),
                // TT-4.10b/SCRUM-291: only pass the record when it actually corresponds to
                // $groupTherapyDTO->user -- CreateGroupTherapyAction sets addedby to
                // $groupTherapyDTO->counsellor when present, NOT to $groupTherapyDTO->user, so
                // the "created as a counsellor" branch must keep falling back to a live check on
                // the user's own account (unchanged from before this ticket) rather than reading
                // a snapshot that was correctly left null for a different reason entirely (no
                // single client at all, not "this user wasn't a minor").
                'for' => $groupTherapyDTO->counsellor ? null : $therapy,
            ])
        );

        SendTherapyAssistanceRequestAction::new()->execute(
            TherapyAssistanceRequestDTO::new()->fromArray([
                'from' => $groupTherapyDTO->counsellor ?: $groupTherapyDTO->user,
                'to' => $groupTherapyDTO->counsellorIds,
                'for' => $therapy,
            ])
        );

        return $therapy;
    }

    // SCRUM-389: after a group is set up -- its creator invites more counsellors, or a counsellor
    // offers to help a public group (from them, to the creator's User, who accepts). Checked first
    // (EnsureCanSendGroupTherapyAssistanceRequestAction); CreateRequestAction adds its own backstop.
    // Checked and sent under the group's lock (the accept path's lock order), so a double submit
    // can't send two. $asked: how many counsellor ids were sent -- one that doesn't exist is refused
    // like one that can't be invited.
    public function sendAssistanceRequest(User $user, ?GroupTherapy $group, Collection $counsellors, int $asked = 0): void
    {
        if ($counsellors->count() < $asked) {
            throw new TherapyException(EnsureCanSendGroupTherapyAssistanceRequestAction::COUNSELLOR_UNAVAILABLE, 422);
        }

        DB::transaction(function () use ($user, $group, $counsellors) {
            $group = $group ? GroupTherapy::query()->lockForUpdate()->find($group->id) : null;
            // SCRUM-394 (review): the counsellor's row straight after the group's (the same order as the
            // accept path), before any plain read -- under REPEATABLE READ a read first would fix the
            // snapshot and the offer cap's count could miss an offer committed meanwhile.
            if ($user->counsellor) {
                EnsureCounsellorOfferCapAction::lockCounsellor($user->counsellor);
            }
            $kind = EnsureCanSendGroupTherapyAssistanceRequestAction::new()->execute($user, $group, $counsellors);

            SendTherapyAssistanceRequestAction::new()->execute(
                TherapyAssistanceRequestDTO::new()->fromArray($kind === 'invite'
                    ? ['from' => $group->addedby, 'to' => $counsellors->pluck('id')->all(), 'for' => $group]
                    : ['from' => $user->counsellor, 'to' => $group->creatorUser(), 'for' => $group])
            );
        });
    }

    public function updateGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        EnsureCanUpdateTherapyAction::new()->execute($groupTherapyDTO);

        // SCRUM-288: settings can't change while the therapy is paused for a missing guardian.
        EnsureMinorHasActiveGuardianAction::new()->execute($groupTherapyDTO->user, $groupTherapyDTO->groupTherapy);

        // TT-7.5b-b1/SCRUM-265: defense-in-depth, mirrors TherapyService::updateTherapy()'s own
        // identical call -- only runs when one of the two gate-related fields is actually being
        // touched (EnsureCanSetGroupTherapyPaymentGateAction itself has no such guard, since it
        // doesn't own these field names; that's this call site's job). A paying client who passes
        // EnsureCanUpdateTherapyAction above (they ARE the addedby) still can't sneak a gate change
        // through the general update endpoint without also being an active counsellor or admin.
        if (! is_null($groupTherapyDTO->strictPaymentGate) || ! is_null($groupTherapyDTO->allowFreeHistoricalAccess)) {
            EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapyDTO->groupTherapy, $groupTherapyDTO->user);
        }

        EnsureTherapyDataIsValidAction::new()->execute($groupTherapyDTO);

        // SCRUM-334: becoming anonymous is checked and saved under a lock on the group's Session rows,
        // the same rows JoinVideoSessionAction locks before opening a join row and reading the group's
        // anonymity. Either the join lands first (and the check sees someone in the call and refuses),
        // or the change does (and the join reads the group as anonymous). Before, a join in between got
        // one unmasked, full-video token.
        // Decided under the lock, after the refresh (security review: deciding "already anonymous" from the
        // copy loaded before the lock could skip the check).
        if ($groupTherapyDTO->anonymous) {
            return DB::transaction(function () use ($groupTherapyDTO) {
                VideoJoinLock::lockSessionRows($groupTherapyDTO->groupTherapy);

                EnsureGroupTherapyCanBecomeAnonymousAction::new()->execute($groupTherapyDTO->groupTherapy->refresh(), $groupTherapyDTO->anonymous);

                return UpdateGroupTherapyAction::new()->execute($groupTherapyDTO);
            });
        }

        return UpdateGroupTherapyAction::new()->execute($groupTherapyDTO);
    }

    // TT-7.5b-b1/SCRUM-265: mirrors TherapyService::updateStrictPaymentGate()'s own precedent
    // exactly -- deliberately separate from updateGroupTherapy() above so an ACTIVE counsellor who
    // is NOT the group's own addedby (the normal case: a counsellor becomes assigned by accepting
    // an assistance request, not by being addedby) can still reach
    // EnsureCanSetGroupTherapyPaymentGateAction's own, self-contained authorization check, bypassing
    // EnsureCanUpdateTherapyAction's addedby-only gate. Also deliberately skips
    // EnsureTherapyDataIsValidAction, same rationale as TT-7.5a's own equivalent (these two
    // settings should stay toggleable regardless of the group's current status/other field state).
    public function updateGroupTherapyPaymentGate(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        // SCRUM-288: no guardian check -- only a group's active counsellors (or an admin) get past the
        // check above, and a group's counsellors are never paused.
        EnsureCanSetGroupTherapyPaymentGateAction::new()->execute($groupTherapyDTO->groupTherapy, $groupTherapyDTO->user);

        return UpdateGroupTherapyAction::new()->execute($groupTherapyDTO);
    }

    public function endGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        EnsureCanUpdateTherapyAction::new()->execute($groupTherapyDTO);

        EnsureCanEndTherapyAction::new()->execute($groupTherapyDTO);

        return EndTherapyAction::new()->execute($groupTherapyDTO);
    }

    public function deleteGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        EnsureCanUpdateTherapyAction::new()->execute($groupTherapyDTO);

        return DeleteTherapyAction::new()->execute($groupTherapyDTO);
    }

    // Full access only: the chat and anything else that shows what happened in the group.
    public function getGroupTherapy(GetTherapyDTO $getTherapyDTO)
    {
        $getTherapyDTO->allowPreview = false;

        return $this->getGroupTherapyPage($getTherapyDTO)['therapy'];
    }

    // The group's page. SCRUM-73/383: with $getTherapyDTO->allowPreview, someone with no other way
    // into a private group may see a reduced preview of it instead -- `preview` says why
    // (EnsureUserHasAccessToTherapyAction::PREVIEW_*): a usable member invitation (`invitation`, the
    // link -- also given for a public group, whose page then offers to join), or having left it.
    /** @return array{therapy: GroupTherapy, invitation: ?Link, preview: ?string} */
    public function getGroupTherapyPage(GetTherapyDTO $getTherapyDTO): array
    {
        EnsureTherapyExistsAction::new()->execute(
            $getTherapyDTO,
            'Group Therapy'
        );

        $group = $getTherapyDTO->groupTherapy;
        $invitation = $getTherapyDTO->allowPreview
            ? FindUsableGroupInvitationAction::new()->execute($getTherapyDTO->invitationUuid, $group, $getTherapyDTO->user)
            : null;

        $preview = EnsureUserHasAccessToTherapyAction::new()->execute($getTherapyDTO, 'groupTherapy', $invitation);

        // SCRUM-360 (security review)
        RecordAdminContentReadAction::new()->execute($getTherapyDTO->user, AdminContentAccess::THERAPY, $group, $group);

        return ['therapy' => $group, 'invitation' => $invitation, 'preview' => $preview];
    }

    public function joinGroupTherapy(JoinGroupTherapyDTO $joinGroupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $joinGroupTherapyDTO,
            'Group Therapy'
        );

        return JoinGroupTherapyAction::new()->execute($joinGroupTherapyDTO);
    }

    // SCRUM-73: no guardian or suspension gate -- leaving is a withdrawal, allowed while paused or
    // suspended (EnsureAccountIsNotSuspended lets this route through).
    public function leaveGroupTherapy(GroupTherapyDTO $groupTherapyDTO)
    {
        EnsureTherapyExistsAction::new()->execute(
            $groupTherapyDTO,
            'Group Therapy'
        );

        return LeaveGroupTherapyAction::new()->execute($groupTherapyDTO->user, $groupTherapyDTO->groupTherapy);
    }

    // The Home page's public groups: public, not one the viewer created (as a user or through their
    // counsellor profile), is a member of, or counsels. SCRUM-113: each narrowing a grouped AND (they
    // used to drop every group a counsellor created, and kept the viewer's own); ranked by the
    // viewer's preferences (a counsellor by their profile) in a seeded order for the visit.
    public function getRandomGroupTherapies(?User $user, mixed $seed = null)
    {
        // SCRUM-386: what each row renders (members, counts, creator) is loaded once for the page by
        // PreloadGroupTherapyListAction -- see listPage().
        $seed = PreferenceRanking::seed($seed);

        $query = GroupTherapy::query()->publicForOutsider($user);

        PreferenceRanking::apply($query, PreferenceMatch::forTherapiesViewedBy($user), $seed);

        return ResolveTherapyCardStateAction::new()->execute($this->listPage($query, ['seed' => $seed]), $user);
    }

    public function getCounsellorGroupTherapies(?User $user)
    {
        if (! $user->counsellor) {
            return [];
        }

        $query = GroupTherapy::query();

        $query->where(function ($query) use ($user) {
            $query->where('addedby_id', $user->counsellor->id)
                ->where('addedby_type', Counsellor::class);
        });

        $query->orWhere(function ($query) use ($user) {
            $query->whereCounsellor($user->counsellor);
        });

        $query->orWhere(function ($query) use ($user) {
            $query->whereHas('discussions', function ($query) use ($user) {
                $query->whereHas('counsellors', function ($query) use ($user) {
                    $query->where('counsellor_id', $user->counsellor->id);
                });
            });
        });

        $query->orWhere(function ($query) use ($user) {
            $query->whereHas('discussions', function ($query) use ($user) {
                $query->whereHas('requests', function ($query) use ($user) {
                    $query
                        ->wherePending()
                        ->whereTo($user->counsellor);
                });
            });
        });

        $query->orWhereHas('requests', function ($query) use ($user) {
            $query
                ->wherePending()
                ->whereTo($user->counsellor);
        });

        $query->latest();

        return $this->listPage($query);
    }

    public function getUserGroupTherapies(?User $user)
    {
        if (! $user) {
            return [];
        }

        $query = GroupTherapy::query();

        $query->where('addedby_id', $user->id);
        $query->where('addedby_type', User::class);

        $query->latest();

        return $this->listPage($query);
    }

    public function getWardGroupTherapies(?User $user)
    {
        if (! $user) {
            return [];
        }

        $query = GroupTherapy::query();

        // TODO test getting them when ward is participating
        $query->where(function ($query) use ($user) {
            $query->whereHasMorph('addedby', [User::class], function ($query) use ($user) {
                $query->whereHas('guardians', function ($query) use ($user) {
                    $query->where('guardian_id', $user->id);
                });
            });
        })->orWhere(function ($query) use ($user) {
            $query->whereHas('users', function ($query) use ($user) {
                $query->whereHas('guardians', function ($query) use ($user) {
                    $query->where('guardian_id', $user->id);
                });
            });
        });

        $query->latest();

        return $this->listPage($query);
    }

    public function getRecentGroupTherapies(?User $user)
    {
        if (is_null($user)) {
            return [];
        }

        return GroupTherapy::query()
            ->whereParticipant($user)
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
    }

    // SCRUM-386: one page of a GroupTherapyMiniResource list, with what each row reads preloaded.
    private function listPage($query, array $appends = [])
    {
        return PreloadGroupTherapyListAction::new()->execute(
            $query->paginate(PaginationEnum::preferencesPagination->value)->appends($appends)
        );
    }
}
