<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Enums\CounsellorGroupTherapyRoleEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\CannotJoinGroupTherapyException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\User;

// SCRUM-358/383: the one way a counsellor joins a group's team -- accepting an assistance invite
// (RespondToGroupTherapyAssistanceRequestAction), the group's creator accepting the counsellor's own
// offer to help (SCRUM-389, $offer), and using a counsellor invitation link
// (PerformGroupTherapyLinkAction). The caller holds the group's row lock (inside its transaction).
// The counsellor becomes an active, normal-role counsellor (a role they already had is kept), unless
// the group is already full. Their other pending invites and offers for the group close (the one
// being accepted is left to its caller); once it fills, everyone's do.
class AddCounsellorToGroupTherapyAction extends Action
{
    public const GROUP_FULL = 'This group already has its maximum number of counsellors.';

    public const NOT_ALLOWED = 'This invitation can no longer be accepted.';

    public const ANSWERED_REASON = 'Joined the group through another invitation or offer.';

    public const FILLED_REASON = 'This group has filled all its counsellor places.';

    // $exceptRequestId: the invite or offer being accepted, left for its caller to close. $offer: the
    // counsellor's own offer to help, when the group's creator ($actor) is accepting it.
    public function execute(GroupTherapy $lockedGroup, ?Counsellor $counsellor, ?User $actor, ?int $exceptRequestId = null, ?Request $offer = null): Counsellor
    {
        $this->ensureCanJoin($lockedGroup, $counsellor, $actor, $offer);

        $activeCounsellors = $lockedGroup->activeCounsellors();
        $alreadyActive = $activeCounsellors->contains('id', $counsellor->id);

        if (! $alreadyActive && ! $lockedGroup->hasFreeCounsellorPlace($activeCounsellors->count())) {
            throw new CannotJoinGroupTherapyException(self::GROUP_FULL, 422);
        }

        $existing = $lockedGroup->counsellors()->whereKey($counsellor->id)->first();
        $lockedGroup->counsellors()->syncWithoutDetaching([
            $counsellor->id => [
                'state' => CounsellorGroupTherapyStateEnum::active->value,
                'role' => $existing?->pivot->role ?? CounsellorGroupTherapyRoleEnum::normal->value,
            ],
        ]);

        // Their other invites and offers for this group are answered by joining.
        $this->closePending($lockedGroup, $exceptRequestId, self::ANSWERED_REASON, $counsellor);

        if (! $lockedGroup->hasFreeCounsellorPlace()) {
            $this->closePending($lockedGroup, $exceptRequestId, self::FILLED_REASON);
        }

        return $counsellor;
    }

    // The group's pending invites and offers (all, or only $counsellor's, either direction) closed --
    // saved one by one, so the Request hooks run (responded_at, the admin counts) and their data
    // (payment terms) is kept beside the reason.
    private function closePending(GroupTherapy $group, ?int $exceptRequestId, string $reason, ?Counsellor $counsellor = null): void
    {
        Request::query()
            ->when($exceptRequestId, fn ($query) => $query->whereNot('id', $exceptRequestId))
            ->pendingGroupAssistance($group)
            ->when($counsellor, fn ($query) => $query->whereToOrFrom($counsellor))
            ->get()
            ->each(fn (Request $request) => $request->forceFill([
                'status' => RequestStatusEnum::inconsequencial->value,
                'data' => [...($request->data ?? []), 'reason' => $reason],
            ])->save());
    }

    // Security review (SCRUM-358): it must be someone who may join: the counsellor themselves (an
    // admin can't accept on their behalf -- joining is their consent), currently verified (SCRUM-31), not the
    // group's own client or a member (who would gain moderation powers over the other members), and a
    // group that hasn't ended. One answer for all of these. SCRUM-73: a former member counts as a
    // member here (the cautious choice) -- they knew the other members as one of them.
    // SCRUM-389: or the group's creator accepting the counsellor's own offer -- their consent is the
    // offer itself (from them, for this group).
    public function ensureCanJoin(GroupTherapy $group, ?Counsellor $counsellor, ?User $actor, ?Request $offer = null): void
    {
        $isThem = $counsellor?->user && $actor && $counsellor->user->is($actor);
        $acceptingTheirOffer = $counsellor && $actor && $offer
            && $offer->from_type === Counsellor::class && (int) $offer->from_id === $counsellor->id
            && $offer->for_type === GroupTherapy::class && (int) $offer->for_id === $group->id
            && $group->isCreatedBy($actor);

        // SCRUM-31: their own verification isn't a secret from them -- the same "re-verify" message
        // as everywhere else; the creator accepting is told it's the counsellor's.
        if ($isThem || $acceptingTheirOffer) {
            EnsureCounsellorMayTakeOnClientsAction::new()->execute($counsellor, asTarget: ! $isThem);
        }

        $allowed = $counsellor
            && $counsellor->user
            && ($isThem || $acceptingTheirOffer)
            && $counsellor->isVerified()
            && $group->status !== TherapyStatusEnum::ended->value
            && ! $group->isUser($counsellor->user)
            && ! $group->hasEverBeenMember($counsellor->user);

        if (! $allowed) {
            throw new CannotJoinGroupTherapyException(self::NOT_ALLOWED, 422);
        }
    }
}
