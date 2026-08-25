<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\User\EnsureUserCanBeGuardianAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Models\Request;
use App\Notifications\GuardianshipEstablishedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RespondToGuardianshipRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        EnsureUserCanBeGuardianAction::new()->execute(
            CreateRequestDTO::new()->fromArray(['to' => $requestResponseDTO->request->to]),
            'You are trying to respond to a guardianship request but do not qualify to be a guardian because you are not an adult, have not set date or birth, have not set email or have not verified your email.'
        );

        // Locking the request row and re-checking its status inside the lock closes the same
        // double-submission race SCRUM-80 fixed for group therapy membership requests: two
        // concurrent responses to the same request both queue on this lock, so the second one
        // always observes the first's committed status update and just no-ops instead of
        // creating a duplicate guardianship row (SCRUM-91).
        //
        // That alone doesn't close a *different* pair's worth of race: the same ward could end
        // up with two separate pending requests to the same guardian (each locks a different
        // Request row, so they don't serialize against each other), and accepting both -- even
        // sequentially, no concurrency required -- would try to create the guardianship row
        // twice. $created only reflects whether *this* call actually created a NEW guardianship
        // row, so a request that turns out to be redundant (the pair already exists) still
        // ends up ACCEPTED but doesn't duplicate the row or re-send the notification. The
        // guardianship(guardian_id, ward_id) unique index (SCRUM-99) is the actual guarantee
        // here -- the existence check is just to avoid an avoidable exception on the common
        // path, and the catch below is the fallback for the residual race the existence check
        // itself can't close on its own.
        [$request, $created] = DB::transaction(function () use ($requestResponseDTO) {
            $request = Request::query()->lockForUpdate()->findOrFail($requestResponseDTO->request->id);

            if ($request->status != RequestStatusEnum::pending->value) {
                return [$request, false];
            }

            $request->update([
                'status' => is_null($requestResponseDTO->response)
                    ? RequestStatusEnum::rejected->value
                    : strtoupper($requestResponseDTO->response),
            ]);

            $request = $request->refresh();

            $created = $request->status == RequestStatusEnum::accepted->value
                && $this->createGuardianshipIfMissing($request);

            return [$request, $created];
        });

        if ($created) {
            $request->from->notify(
                new GuardianshipEstablishedNotification($request->to)
            );
        }

        return $request;
    }

    private function createGuardianshipIfMissing(Request $request): bool
    {
        if ($request->from->guardians()->where('guardian_id', $request->to->id)->exists()) {
            return false;
        }

        try {
            $request->from->guardians()->create(['guardian_id' => $request->to->id]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
