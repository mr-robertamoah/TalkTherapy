<?php

namespace App\Actions\Therapy;

use App\Actions\Action;
use App\Actions\Transaction\EnsureStrictPaymentGateSatisfiedAction;
use App\DTOs\GetTherapyDTO;
use App\Exceptions\TherapyAccessDeniedException;
use App\Models\Discussion;
use App\Models\Link;
use App\Models\Request;

// Returns null for full access. SCRUM-73/383: when the caller allows it ($getTherapyDTO->allowPreview,
// the group's page only), someone with no other way into a private group may still see a reduced
// preview of it (GroupTherapyPreviewResource) -- the returned PREVIEW_* says why: a usable member
// invitation ($invitation, from FindUsableGroupInvitationAction), or having been a member.
class EnsureUserHasAccessToTherapyAction extends Action
{
    public const PREVIEW_INVITATION = 'invitation';

    public const PREVIEW_FORMER_MEMBER = 'former_member';

    public function execute(GetTherapyDTO $getTherapyDTO, string $type = 'therapy', ?Link $invitation = null): ?string
    {
        $therapy = $getTherapyDTO->$type;
        $user = $getTherapyDTO->user;

        if ($therapy->public) {
            return null;
        }

        // A guest (null user) can never satisfy any of the below checks -- isParticipant()
        // is non-nullable-typed, so without this explicit guard a guest on a non-public
        // therapy would fail closed only by accident, via an uncaught TypeError the calling
        // controller's generic catch block happens to redirect (SCRUM-76).
        if (! $user) {
            throw new TherapyAccessDeniedException(self::deniedMessage($therapy), 422);
        }

        // SCRUM-219/TT-7.5a: the client (the therapy's own addedby) is the only participant ever
        // subject to the strict payment gate below. Every other bypass in the big OR below
        // (counsellor, admin, guardian, pending-request-counsellor) grants unconditional access,
        // unchanged.
        //
        // TT-7.5b-b2/SCRUM-266: widened to GroupTherapy, where "the client" isn't a single
        // addedby -- it's every member (creator or joined via the group_therapy_user pivot) who
        // is NOT also an active counsellor on the group. A counsellor is never gated regardless
        // of how they're attached (addedby, or via counsellors()), matching b0's "counsellors run
        // the group, they don't pay into it" precedent. isParticipant() already covers exactly
        // "addedby / counsellor / pivot member", so excluding the counsellor case here is
        // sufficient -- no separate pivot-membership lookup needed.
        $isGroupTherapyMember = $therapy->is_group_therapy
            && $therapy->isParticipant($user)
            && ! ($user->counsellor && $therapy->isCounsellor($user->counsellor));

        $isClient = ($therapy->is_therapy && $therapy->addedby->is($user)) || $isGroupTherapyMember;

        if (
            $therapy->isParticipant($user) ||
            (
                $user->counsellor &&
                (
                    Request::query()
                        ->wherePending()
                        ->whereTo($user->counsellor)
                        ->whereHasMorph('for', [Discussion::class], function ($query) use ($therapy) {
                            $query->whereFor($therapy);
                        })
                        ->exists() ||
                    $user->counsellor->hasPendingRequestFor($therapy)
                )
            ) ||
            $user->isAdmin() ||
            (
                $therapy->is_therapy &&
                $user->isGuardianOf($therapy->addedby)
            ) ||
            (
                $therapy->is_group_therapy &&
                $user->isGuardianOfAUserFor($therapy)
            )
        ) {
            // SCRUM-220: page-load is always the PER_THERAPY-payable case (no single Session is
            // in view here) -- the PER_SESSION case is gated separately, per-Session, inside
            // MessageService (EnsureUserCanAccessTherapyContentAction), not here.
            if ($isClient) {
                EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user);
            }

            return null;
        }

        if ($therapy->is_group_therapy && $getTherapyDTO->allowPreview) {
            if ($invitation) {
                return self::PREVIEW_INVITATION;
            }

            if ($therapy->isFormerMember($user)) {
                return self::PREVIEW_FORMER_MEMBER;
            }
        }

        throw new TherapyAccessDeniedException(self::deniedMessage($therapy), 422);
    }

    // SCRUM-385: never name (or number) the therapy in the denial -- the caller isn't allowed to
    // see it, so just say which kind of thing it was.
    private static function deniedMessage($therapy): string
    {
        return $therapy->is_group_therapy
            ? 'You are not allowed to access this group therapy.'
            : 'You are not allowed to access this therapy.';
    }
}
