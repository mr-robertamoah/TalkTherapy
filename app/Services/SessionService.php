<?php

namespace App\Services;

use App\Actions\Session\ChangeSessionStatusAction;
use App\Actions\Session\CreateSessionAction;
use App\Actions\Session\DeleteSessionAction;
use App\Actions\Session\EnsureCanCreateSessionAction;
use App\Actions\Session\EnsureCanDeleteSessionAction;
use App\Actions\Session\EnsureCanEndSessionAction;
use App\Actions\Session\EnsureCanUpdateSessionAction;
use App\Actions\Session\EnsureSessionDataIsValidAction;
use App\Actions\Session\EnsureSessionExistsAction;
use App\Actions\Session\UpdateSessionAction;
use App\Actions\Star\CreateStarAction;
use App\Actions\Therapy\EnsureTherapyExistsAction;
use App\DTOs\CreateSessionDTO;
use App\DTOs\CreateStarDTO;
use App\Enums\PaginationEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\StarTypeEnum;
use App\Http\Resources\SessionResource;
use App\Models\Therapy;
use App\Models\User;

class SessionService extends Service
{
    public function getSessions(Therapy $therapy, User $user)
    {
        if (
            $user->isNotAdmin() &&
            !$therapy->public &&
            $therapy->isNotParticipant($user)
        ) return [];
        
        return SessionResource::collection($therapy->sessions()->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function createSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureTherapyExistsAction::new()->execute($createSessionDTO);

        EnsureCanCreateSessionAction::new()->execute($createSessionDTO);

        EnsureSessionDataIsValidAction::new()->execute($createSessionDTO);
        
        $session = CreateSessionAction::new()->execute($createSessionDTO);

        CreateStarAction::new()->execute(
            CreateStarDTO::fromArray([
                'starredby' => null,
                'starred' => $createSessionDTO->user,
                'starreable' => $session,
                'type' => StarTypeEnum::participation->value,
            ])
        );

        return $session;
    }

    public function updateSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanUpdateSessionAction::new()->execute($createSessionDTO);

        EnsureSessionDataIsValidAction::new()->execute($createSessionDTO);

        return UpdateSessionAction::new()->execute($createSessionDTO);
    }

    public function endSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanEndSessionAction::new()->execute($createSessionDTO);

        return ChangeSessionStatusAction::new()->execute($createSessionDTO, SessionStatusEnum::held->value);
    }

    public function getInSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanEndSessionAction::new()->execute($createSessionDTO);

        return ChangeSessionStatusAction::new()->execute($createSessionDTO, SessionStatusEnum::in_session->value);
    }

    public function abandonSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanEndSessionAction::new()->execute($createSessionDTO);

        return ChangeSessionStatusAction::new()->execute($createSessionDTO, SessionStatusEnum::abandoned->value);
    }

    public function failSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanEndSessionAction::new()->execute($createSessionDTO);

        return ChangeSessionStatusAction::new()->execute($createSessionDTO, SessionStatusEnum::failed->value);
    }

    public function deleteSession(CreateSessionDTO $createSessionDTO)
    {
        EnsureSessionExistsAction::new()->execute($createSessionDTO);

        EnsureCanDeleteSessionAction::new()->execute($createSessionDTO);

        return DeleteSessionAction::new()->execute($createSessionDTO);
    }
}