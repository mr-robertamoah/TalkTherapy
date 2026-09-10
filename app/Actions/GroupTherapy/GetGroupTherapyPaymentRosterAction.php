<?php

namespace App\Actions\GroupTherapy;

use App\Actions\Action;
use App\Enums\ConstantsEnum;
use App\Models\GroupTherapy;

// TT-7.4d-d/SCRUM-261: the counsellor-facing per-member payment roster. Architect finding
// (mandatory): must eager-load `users` together with EVERY member's transactions in one extra
// query and match them in PHP -- calling TherapyTrait::latestTransactionFor($member) in a loop
// over each member would reintroduce the exact N-query-per-member pattern
// GetCounsellorCalendarSessionsAction/TT-7.7e already had to fix twice.
//
// This action performs NO authorization of its own -- it always returns every member's real (or
// per-member-anonymized) identity unconditionally. It must only ever be called after the caller
// has independently verified the viewer is THIS group's own counsellor (see
// GroupTherapyResource's $isRosterEligibleCounsellor guard, its only call site today).
class GetGroupTherapyPaymentRosterAction extends Action
{
    public function execute(GroupTherapy $groupTherapy): array
    {
        $groupTherapy->loadMissing([
            'users',
            // Ordered once, here -- ->first() per group below then picks each member's latest
            // without a second sort, mirroring latestTransactionFor()'s own `latest('created_at')`.
            'transactions' => fn ($query) => $query->latest('created_at'),
        ]);

        // Collection::groupBy() preserves each group's original (already-sorted) order -- since
        // the eager-load above already sorted every transaction newest-first, ->first() on each
        // group below is safe. This is a subtle enough property that a future refactor swapping
        // groupBy() for something else could silently break "latest wins" without realizing it.
        $latestTransactionByUserId = $groupTherapy->transactions->groupBy('user_id');

        return $groupTherapy->users->map(function ($member) use ($latestTransactionByUserId) {
            $transaction = $latestTransactionByUserId->get($member->id)?->first();

            // Security review finding (user-confirmed, TT-7.4d-d): this roster's own approved
            // exception is scoped to the GROUP's `anonymous` flag only (GroupTherapyResource's
            // $isRosterEligibleCounsellor guard already ignores it entirely, by design). A
            // member's INDEPENDENT per-member opt-in (`group_therapy_user.anonymous`, the same
            // pivot flag MessageResource/RequestResource already mask identity for, from
            // everyone including the counsellor) is a separate signal this roster must still
            // respect -- deliberately NOT GroupTherapy::isAnonymousFor(), which ORs in the
            // group-level flag too and would incorrectly mask everyone on an anonymous group.
            $isMemberAnonymous = (bool) $member->pivot->anonymous;

            return [
                'id' => $member->id,
                // Deliberately hand-rolled rather than UserMiniResource (which carries
                // gender/country/dob/isUser -- fields this minimal, payment-only roster row
                // doesn't need) -- mirrors GroupTherapyResource's own masked-`addedby` shape
                // instead (id kept, name replaced) -- username additionally nulled here since,
                // unlike a bare id, a username is itself directly identifying.
                'fullName' => $isMemberAnonymous ? ConstantsEnum::anonymousUserLabel->value : $member->name,
                'username' => $isMemberAnonymous ? null : $member->username,
                // Deliberately just "paymentStatus", not "viewerPaymentStatus" -- unlike
                // GroupTherapyResource's own field of that name (scoped to whoever is CURRENTLY
                // viewing), each roster row is already scoped to the specific member it describes,
                // so there is no separate "viewer" to distinguish it from. Never masked -- the
                // whole point of this roster is payment reconciliation, which stays meaningful for
                // an anonymous member even with their name/username withheld.
                'paymentStatus' => $transaction?->status,
                'transactionId' => $transaction?->id,
            ];
        })->values()->all();
    }
}
