<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\Actions\Transaction\EnsureStrictPaymentGateSatisfiedAction;
use App\Exceptions\PaymentRequiredException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

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
class EnsureUserCanAccessTherapyContentAction extends Action
{
    // $session, when the content in question belongs to one specific Session, enables PER_SESSION
    // strict-gate checking against that Session -- omit it when no single session applies.
    public function execute(Therapy|GroupTherapy|null $therapy, User $user, ?Session $session = null): bool
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

        if ($therapy instanceof Therapy && $therapy->addedby->is($user)) {
            try {
                EnsureStrictPaymentGateSatisfiedAction::new()->execute($therapy, $user, $session);
            } catch (PaymentRequiredException) {
                return false;
            }
        }

        return true;
    }
}
