<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\User\AlertGuardianAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GuardianAlertDTO;
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
