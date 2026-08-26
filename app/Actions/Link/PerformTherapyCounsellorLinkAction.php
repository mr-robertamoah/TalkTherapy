<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GuardianAlertDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Enums\RequestStatusEnum;
use App\Exceptions\LinkException;
use App\Models\Request;
use App\Models\Therapy;
use App\Notifications\TherapyAssistanceLinkNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

class PerformTherapyCounsellorLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        // Without this, a non-counsellor user reaching this action (e.g. via a bug elsewhere
        // in the auth chain) would hit an uncaught Error dereferencing a null ->counsellor
        // below, instead of a clean LinkException -- SCRUM-101.
        EnsureCounsellorExistsAction::new()->execute(
            UpdateCounsellorDTO::new()->fromArray(['counsellor' => $createLinkDTO->user?->counsellor]),
            'You do not have a counsellor account hence you are not authorized to use this link. Create a counsellor account first.'
        );

        // Locking the therapy row before deciding whether it already has a counsellor closes
        // the same TOCTOU race SCRUM-91 fixed for RespondToTherapyAssistanceRequestAction: two
        // concurrent uses of a therapy-counsellor link (or one racing a request-based accept)
        // could otherwise both see no counsellor assigned yet and both "win", losing one of the
        // two counsellor_id writes (SCRUM-98). Locking the therapy row before touching any
        // Request row (here, the sibling-invalidation update below) keeps this on the same
        // therapy-before-request lock order as RespondToTherapyAssistanceRequestAction, so the
        // two code paths can never deadlock against each other.
        $therapy = DB::transaction(function () use ($createLinkDTO) {
            $therapy = Therapy::query()->lockForUpdate()->findOrFail($createLinkDTO->link->for->id);

            if ($therapy->counsellor) {
                throw new LinkException('Sorry, link cannot be used because therapy already has a counsellor', 422);
            }

            $therapy->update([
                'counsellor_id' => $createLinkDTO->user->counsellor->id,
            ]);

            Request::query()
                ->wherePending()
                ->whereFor($therapy)
                ->update([
                    'status' => RequestStatusEnum::inconsequencial->value,
                    'data' => [
                        'reason' => 'A similar request for therapy assistance has been accepted by someone else.',
                    ],
                ]);

            // Deactivating on successful use (SCRUM-101) keeps this link from being replayed --
            // done inside the same transaction so a rollback (e.g. the already-has-a-counsellor
            // throw above) never leaves the link deactivated without an actual assignment.
            $createLinkDTO->link->deactivate();

            return $therapy->refresh();
        });

        $createLinkDTO->link->addedby->notify(
            new TherapyAssistanceLinkNotification($therapy)
        );

        AlertGuardianAction::new()->execute(
            GuardianAlertDTO::new()->fromArray([
                'user' => $createLinkDTO->link->addedby,
                'notification' => new TherapyAssistanceRequestAcceptedGuardianNotification(
                    $therapy
                ),
            ])
        );

        return Redirect::route('therapies.get', ['therapyId' => $therapy->id]);
    }
}
