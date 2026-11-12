<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\Actions\GroupTherapy\AddCounsellorToGroupTherapyAction;
use App\Actions\GroupTherapy\AddGroupTherapyMemberAction;
use App\Actions\Therapy\EnsureCanCreateTherapyAction;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Enums\ConstantsEnum;
use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\GuardianRequiredException;
use App\Exceptions\LinkException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Link;
use App\Models\Request;
use App\Models\User;
use App\Notifications\GroupTherapyCounsellorLinkUsedNotification;
use App\Notifications\GroupTherapyMemberJoinedGuardianNotification;
use App\Support\LinkConfirmation;
use App\Support\TherapyLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

// SCRUM-383: using a group's invitation links.
//  - COUNSELLOR (SCRUM-418): opening it only shows the group (previewAsCounsellor()); confirming
//    (joinAsCounsellor(), from api.links.join) joins the group's team (AddCounsellorToGroupTherapyAction),
//    the same as accepting an invite, whose pending invite then closes.
//  - PARTICIPANT: opening it only takes the invitee to the group's page with a "join?" confirmation
//    (a GET can't carry their anonymity choice); confirming (joinAsMember()) adds them without the
//    creator's approval -- the creator issued the link -- through the same step as every other join.
//  - ADMIN: switched off (the app has no group-admin role), told the same as an inactive link.
// Every use locks the link, then the group, then any request it closes, and re-checks the link is
// active under its lock, so two people racing one link can't both use it. Each link works once.
// SCRUM-382/384: nobody is let into (or onto the team of) a group whose creator is a minor paused for
// a missing guardian -- told neutrally, re-read under the lock. A suspended counsellor is refused
// before this (EnsureCanUseLinkAction::COUNSELLOR_TYPES).
class PerformGroupTherapyLinkAction extends Action
{
    public const INVITED = 'You have been invited to join this group. Confirm on this page to join.';

    public const JOINED = 'You have joined this group therapy.';

    public const COUNSELLOR_JOINED = 'You are now a counsellor of this group therapy.';

    // Opening a group link (PerformLinkAction): a member invitation goes to the group's page; a counsellor
    // invitation is shown by LinkService (previewAsCounsellor()), never used by opening it.
    public function execute(CreateLinkDTO $createLinkDTO): RedirectResponse
    {
        return match ($createLinkDTO->link->type) {
            LinkTypeEnum::groupTherapyParticipant->value => $this->inviteToJoin($createLinkDTO),
            default => throw LinkException::inactive(),
        };
    }

    // SCRUM-418: what a counsellor invitation shows before they confirm -- the group's name, about and
    // whether it's anonymous (read directly, not through its card's resource, SCRUM-419), its creator by the name they're shown (masked for
    // an anonymous one), and none of its members. The same checks as joinAsCounsellor(), unlocked.
    public function previewAsCounsellor(CreateLinkDTO $createLinkDTO): array
    {
        $counsellor = $this->counsellorOf($createLinkDTO);
        $group = $createLinkDTO->link->for;

        if (! $group instanceof GroupTherapy) {
            throw LinkException::inactive();
        }

        $this->ensureCreatorIsNotPaused($group);
        $this->ensureCanJoinAsCounsellor($group, $counsellor, $createLinkDTO->user);

        return [
            'name' => $group->name,
            'about' => $group->about,
            'anonymous' => $group->anonymous,
            'createdBy' => $this->creatorName($group),
        ];
    }

    // The invitee confirmed on the group's page, with their anonymity choice.
    public function joinAsMember(CreateLinkDTO $createLinkDTO): GroupTherapy
    {
        $user = $createLinkDTO->user;

        return DB::transaction(function () use ($createLinkDTO, $user) {
            [$link, $group] = $this->lockLinkAndGroup($createLinkDTO->link, LinkTypeEnum::groupTherapyParticipant->value);

            // The guardian gate for joining, as from the group's page.
            EnsureCanCreateTherapyAction::new()->execute($user);

            // Also tells a minor's guardians once this commits.
            AddGroupTherapyMemberAction::new()->execute($group, $user, $createLinkDTO->anonymous, GroupTherapyMemberJoinedGuardianNotification::VIA_LINK);

            Request::query()
                ->wherePending()
                ->whereFor($group)
                ->where('type', RequestTypeEnum::groupTherapyMembership->value)
                ->whereFrom($user)
                ->update([
                    'status' => RequestStatusEnum::inconsequencial->value,
                    'responded_at' => now(),
                    'data' => ['reason' => 'Joined through an invitation link.'],
                ]);

            $link->markUsed();

            return $group->refresh();
        });
    }

    public function joinAsCounsellor(CreateLinkDTO $createLinkDTO): LinkConfirmation
    {
        $user = $createLinkDTO->user;
        $counsellor = $this->counsellorOf($createLinkDTO);

        $group = DB::transaction(function () use ($createLinkDTO, $user, $counsellor) {
            [$link, $group] = $this->lockLinkAndGroup($createLinkDTO->link, LinkTypeEnum::groupTherapyCounsellor->value);

            AddCounsellorToGroupTherapyAction::new()->execute($group, $counsellor, $user);

            // Their own pending invites and offers for this group are closed by joining
            // (AddCounsellorToGroupTherapyAction, SCRUM-389).

            $link->markUsed();

            return $group->refresh();
        });

        $group->creatorUser()?->notify(new GroupTherapyCounsellorLinkUsedNotification($group, $counsellor));

        // A minor creator's guardians, by the group's creation-time snapshot (clientIsMinor()).
        if ($group->addedby instanceof User) {
            AlertGuardianAction::new()->execute(
                GuardianAlertDTO::new()->fromArray([
                    'user' => $group->addedby,
                    'notification' => new GroupTherapyCounsellorLinkUsedNotification($group, $counsellor, toGuardian: true),
                    'for' => $group,
                ])
            );
        }

        return new LinkConfirmation(self::COUNSELLOR_JOINED, TherapyLink::to($group));
    }

    private function counsellorOf(CreateLinkDTO $createLinkDTO): Counsellor
    {
        EnsureCounsellorExistsAction::new()->execute(
            UpdateCounsellorDTO::new()->fromArray(['counsellor' => $createLinkDTO->user?->counsellor]),
            LinkException::NO_COUNSELLOR
        );

        return $createLinkDTO->user->counsellor;
    }

    // Who may join a team, and a free place (AddCounsellorToGroupTherapyAction runs both again, locked).
    private function ensureCanJoinAsCounsellor(GroupTherapy $group, Counsellor $counsellor, User $user): void
    {
        AddCounsellorToGroupTherapyAction::new()->ensureCanJoin($group, $counsellor, $user);
        AddCounsellorToGroupTherapyAction::new()->ensureHasPlace($group, $counsellor);
    }

    private function creatorName(GroupTherapy $group): ?string
    {
        if ($group->addedby instanceof Counsellor) {
            return $group->addedby->getName();
        }

        // The name others see (the one masking rule, displayNameOf()) -- never their own view of it.
        return $group->addedby instanceof User ? $group->displayNameOf($group->addedby) : ConstantsEnum::anonymousUserLabel->value;
    }

    // Opening a member invitation: the same checks confirming will make (none of them locked -- they
    // are run again, under the locks, when the invitee confirms), then the group's page.
    private function inviteToJoin(CreateLinkDTO $createLinkDTO): RedirectResponse
    {
        $group = $createLinkDTO->link->for;

        if (! $group instanceof GroupTherapy) {
            throw LinkException::inactive();
        }

        $this->ensureCreatorIsNotPaused($group);
        EnsureCanCreateTherapyAction::new()->execute($createLinkDTO->user);
        AddGroupTherapyMemberAction::new()->ensureCanAdd($group, $createLinkDTO->user);

        return Redirect::to(TherapyLink::to($group).'?'.http_build_query(['invitation' => $createLinkDTO->link->uuid]))
            ->with('message', self::INVITED);
    }

    /** @return array{0: Link, 1: GroupTherapy} */
    private function lockLinkAndGroup(Link $link, string $type): array
    {
        $link = Link::query()->lockForUpdate()->find($link->id);

        if (! $link || $link->state !== LinkStateEnum::active->value || $link->type !== $type || $link->for_type !== GroupTherapy::class) {
            throw LinkException::inactive();
        }

        $group = GroupTherapy::query()->lockForUpdate()->find($link->for_id);

        if (! $group) {
            throw LinkException::inactive();
        }

        $this->ensureCreatorIsNotPaused($group);

        return [$link, $group];
    }

    private function ensureCreatorIsNotPaused(GroupTherapy $group): void
    {
        if ($group->creatorIsPausedForMissingGuardian(fresh: true)) {
            throw GuardianRequiredException::linkUnavailable();
        }
    }
}
