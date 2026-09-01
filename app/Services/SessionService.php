<?php

namespace App\Services;

use App\Actions\Session\AfterSessionCreatedAction;
use App\Actions\Session\ChangeSessionStatusAction;
use App\Actions\Session\CreateSessionAction;
use App\Actions\Session\DeleteSessionAction;
use App\Actions\Session\EnsureCanChangeSessionTopicAction;
use App\Actions\Session\EnsureCanCreateSessionAction;
use App\Actions\Session\EnsureCanDeleteSessionAction;
use App\Actions\Session\EnsureCanEndSessionAction;
use App\Actions\Session\EnsureCanUpdateSessionAction;
use App\Actions\Session\EnsureCanUpdateSessionStatusAction;
use App\Actions\Session\EnsureSessionDataIsValidAction;
use App\Actions\Session\EnsureSessionExistsAction;
use App\Actions\Session\EnsureTherapyExistsAction;
use App\Actions\Session\SetCurrentTopicOfSessionAction;
use App\Actions\Session\UnsetCurrentTopicOfSessionAction;
use App\Actions\Session\UpdateSessionAction;
use App\Actions\TherapyTopic\EnsureTherapyTopicExistsAction;
use App\DTOs\CreateSessionDTO;
use App\DTOs\GetSessionsDTO;
use App\Enums\PaginationEnum;
use App\Enums\SessionStatusEnum;
use App\Events\SessionTopicSetEvent;
use App\Events\SessionTopicUnsetEvent;
use App\Events\SessionUpdatedEvent;
use App\Http\Resources\SessionResource;
use App\Notifications\SessionDeletedNotification;
use App\Notifications\SessionStatusChangedNotification;
use App\Notifications\SessionUpdatedNotification;
use Illuminate\Support\Facades\Notification;

class SessionService extends Service
{
    public function getSessions(GetSessionsDTO $getSessionsDTO)
    {
        $user = $getSessionsDTO->user;
        $therapy = $getSessionsDTO->therapy;

        if (! $therapy) {
            return [];
        }

        // SCRUM-74: the previous `$user?->isNotAdmin() && ...` form silently skipped this
        // restriction entirely for an unauthenticated (null $user) request instead of denying
        // it -- this route is intentionally reachable by guests for PUBLIC therapies (mirroring
        // this endpoint's existing design), so a null $user must still see public sessions, but
        // must never fall through to the private/non-participant branch below.
        $isAdmin = $user?->isNotAdmin() === false;

        if (! $isAdmin && ! $therapy->public && (! $user || $therapy->isNotParticipant($user))) {
            return [];
        }

        $query = $therapy->sessions()->with('latestTransaction')->when($getSessionsDTO->name, function ($query) use ($getSessionsDTO) {
            $query->whereNameLike($getSessionsDTO->name);
        });

        return SessionResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureTherapyExistsAction::new()->execute($createSessionDTO);

        EnsureCanCreateSessionAction::new()->execute($createSessionDTO);

        EnsureSessionDataIsValidAction::new()->execute($createSessionDTO);

        $session = CreateSessionAction::new()->execute($createSessionDTO);

        AfterSessionCreatedAction::new()->execute($session, $createSessionDTO->user);

        return $session;
    }

    public function updateSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanUpdateSessionAction::new()->execute($createSessionDTO);

        EnsureSessionDataIsValidAction::new()->execute($createSessionDTO);

        $session = UpdateSessionAction::new()->execute($createSessionDTO);

        Notification::send(
            $session->for->getOtherUsers($createSessionDTO->user),
            new SessionUpdatedNotification($session)
        );

        return $session;
    }

    public function endSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanEndSessionAction::new()->execute($createSessionDTO);

        $session = ChangeSessionStatusAction::new()->execute($createSessionDTO, SessionStatusEnum::held->value);

        broadcast(new SessionUpdatedEvent($session))->toOthers();

        Notification::send(
            $createSessionDTO->session->for->getOtherUsers($createSessionDTO->user),
            new SessionStatusChangedNotification($session)
        );

        return $session;
    }

    public function getInSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanUpdateSessionStatusAction::new()->execute($createSessionDTO);

        $session = ChangeSessionStatusAction::new()->execute($createSessionDTO, SessionStatusEnum::in_session->value);

        broadcast(new SessionUpdatedEvent($session))->toOthers();

        Notification::send(
            $createSessionDTO->session->for->getOtherUsers($createSessionDTO->user),
            new SessionStatusChangedNotification($session)
        );

        return $session;
    }

    public function abandonSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanUpdateSessionStatusAction::new()->execute($createSessionDTO);

        $session = ChangeSessionStatusAction::new()->execute($createSessionDTO, SessionStatusEnum::abandoned->value);

        broadcast(new SessionUpdatedEvent($session))->toOthers();

        Notification::send(
            $createSessionDTO->session->for->getOtherUsers($createSessionDTO->user),
            new SessionStatusChangedNotification($session)
        );

        return $session;
    }

    public function failSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanEndSessionAction::new()->execute($createSessionDTO);

        $session = ChangeSessionStatusAction::new()->execute($createSessionDTO, SessionStatusEnum::failed->value);

        Notification::send(
            $createSessionDTO->session->for->getOtherUsers($createSessionDTO->user),
            new SessionStatusChangedNotification($session)
        );

        return $session;
    }

    public function deleteSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanDeleteSessionAction::new()->execute($createSessionDTO);

        $session = DeleteSessionAction::new()->execute($createSessionDTO);

        Notification::send(
            $createSessionDTO->session->for->getOtherUsers($createSessionDTO->user),
            new SessionDeletedNotification($session)
        );

        return $session;
    }

    public function setCurrentTopic(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureTherapyTopicExistsAction::new()->execute($createSessionDTO);

        EnsureCanChangeSessionTopicAction::new()->execute($createSessionDTO);

        $session = SetCurrentTopicOfSessionAction::new()->execute($createSessionDTO);

        broadcast(new SessionTopicSetEvent($session))->toOthers();

        return $session;
    }

    public function unsetCurrentTopic(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureTherapyTopicExistsAction::new()->execute($createSessionDTO);

        EnsureCanChangeSessionTopicAction::new()->execute($createSessionDTO);

        $session = UnsetCurrentTopicOfSessionAction::new()->execute($createSessionDTO);

        broadcast(new SessionTopicUnsetEvent($session))->toOthers();

        return $session;
    }
}
