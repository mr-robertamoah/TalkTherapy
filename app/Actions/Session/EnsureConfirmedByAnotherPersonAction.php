<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\DTOs\CreateSessionDTO;
use App\Enums\SessionStatusEnum;
use App\Exceptions\SessionException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-443: starting and ending a session take two people -- one asks (IN_SESSION_CONFIRMATION / HELD_CONFIRMATION,
// recorded as its updatedby), someone else confirms. Only the page hid the confirm button from whoever asked; the
// server let the same person confirm their own request, so one person could mark a session held (and bill an
// organisation for it) alone. The confirmer is on the other side: a client or member confirms a counsellor's request,
// a counsellor (or an administrator) a client's or member's.
class EnsureConfirmedByAnotherPersonAction extends Action
{
    public const SAME_PERSON = 'The other side of the session needs to confirm this: the client (or a member) for a counsellor, the counsellor for a client.';

    public function execute(CreateSessionDTO $createSessionDTO, string $requested): void
    {
        $session = $createSessionDTO->session;

        $confirming = ($requested === SessionStatusEnum::in_session->value && $session->status === SessionStatusEnum::in_session_confirmation->value)
            || ($requested === SessionStatusEnum::held->value && $session->status === SessionStatusEnum::held_confirmation->value);

        if (! $confirming) {
            return;
        }

        $asker = $session->updatedby;
        $askerUser = $asker instanceof Counsellor ? $asker->user : ($asker instanceof User ? $asker : null);

        if (! $askerUser) {
            return;
        }

        // The other side (security review): in a group, two members -- or a counsellor's second account among them --
        // could otherwise start and end a session (and bill it) without its counsellor. Administrators stand with the
        // counsellors.
        if ($askerUser->is($createSessionDTO->user) || $this->counsellorSide($askerUser, $session->for) === $this->counsellorSide($createSessionDTO->user, $session->for)) {
            throw new SessionException(self::SAME_PERSON, 422);
        }
    }

    private function counsellorSide(User $user, Therapy|GroupTherapy|null $therapy): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->counsellor || ! $therapy) {
            return false;
        }

        return (bool) $therapy->isCounsellor($user->counsellor);
    }
}
