<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\Actions\GroupTherapy\GetGroupTherapyMemberJoinDateAction;
use App\Actions\Transaction\EnsureStrictPaymentGateSatisfiedAction;
use App\Exceptions\OrganizationBillingSuspendedException;
use App\Exceptions\PaymentRequiredException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Carbon;

// SCRUM-220/TT-7.5a: the ONE shared check MessageService's getSessionMessages(),
// getTherapyTopicMessages(), and getMessageReplies() all delegate to, consolidating what were 3
// independently-duplicated copies of "public OR isParticipant OR admin" -- deliberately the same
// (narrower) bypass set those 3 methods already used, NOT EnsureUserHasAccessToTherapyAction's
// richer one (no guardian/pending-request-counsellor bypass here -- that's pre-existing behavior,
// unrelated to payment gating, and not something this ticket changes).
// getDiscussionMessages() is NOT consolidated onto this -- Discussion::isParticipant() takes a
// Counsellor, not a User, and always returns false for a null counsellor, so a plain client can
// never be a Discussion participant at all; there is no client-payment-gating scenario there.
//
// A boolean check (not throwing), matching MessageService's existing "return []" idiom rather
// than PaymentRequiredException's exception-driven flow (that's for controller/page-load call
// sites only -- EnsureUserHasAccessToTherapyAction).
//
// TT-7.5b-b3/SCRUM-267: widened to actually gate a GroupTherapy member here too -- previously this
// action only ever checked `$therapy instanceof Therapy`, so a strict-gated GroupTherapy's
// session/topic/reply content stayed fully reachable through this action regardless of payment
// status, even after TT-7.5b-b2 correctly blocked the group's own page load. Also adds the
// late-joiner "historical content" exemption: a GroupTherapy member whose content predates their
// own join date is never gated for it, when the group's allowFreeHistoricalAccess setting is on.
class EnsureUserCanAccessTherapyContentAction extends Action
{
    // $session, when the content in question belongs to one specific Session, enables PER_SESSION
    // strict-gate checking against that Session -- omit it when no single session applies.
    //
    // $contentTimestamp is the late-joiner exemption's own comparison point -- deliberately NOT
    // defaulted from $session->start_time internally, and deliberately never passed by
    // EnsureCanSendMessageToForAction's message-CREATION call site: a message being created right
    // now has no historical timestamp of its own to exempt on (that would let a member in a
    // long-running session that predates their join send unlimited new messages for free forever,
    // not just read the old ones) -- only the 3 read call sites below pass this, each with the
    // timestamp that actually matches what they're fetching (a session's own start_time for
    // getSessionMessages/getTherapyTopicMessages, since those fetch a full session's worth of
    // content at once; a specific parent message's own created_at for getMessageReplies, since
    // replies are fetched per-message, not per-session).
    public function execute(Therapy|GroupTherapy|null $therapy, User $user, ?Session $session = null, ?Carbon $contentTimestamp = null): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $therapy) {
            return false;
        }

        if (! $therapy->public && $therapy->isNotParticipant($user)) {
            return false;
        }

        $isTherapyClient = $therapy instanceof Therapy && $therapy->addedby->is($user);

        // Mirrors EnsureUserHasAccessToTherapyAction's own identical GroupTherapy membership
        // check (TT-7.5b-b2) -- any participant who is NOT also an active counsellor is "the
        // client" subject to the gate here too.
        $isGroupTherapyMember = $therapy instanceof GroupTherapy
            && $therapy->isParticipant($user)
            && ! ($user->counsellor && $therapy->isCounsellor($user->counsellor));

        if ($isTherapyClient || $isGroupTherapyMember) {
            if ($isGroupTherapyMember && $contentTimestamp && $therapy->allowFreeHistoricalAccess) {
                $joinDate = GetGroupTherapyMemberJoinDateAction::new()->execute($therapy, $user);

                if ($joinDate && $contentTimestamp->lt($joinDate)) {
                    return true;
                }
            }

            try {
                EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user, $session);
            } catch (PaymentRequiredException|OrganizationBillingSuspendedException) {
                return false;
            }
        }

        return true;
    }
}
