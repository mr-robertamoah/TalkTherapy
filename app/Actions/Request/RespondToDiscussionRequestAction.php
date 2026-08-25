<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Discussion\EnsureNotAlreadyPartOfDiscussionAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Events\DiscussionRequestResponseEvent;
use App\Models\Request;
use App\Notifications\DiscussionInclusionNotification;
use Illuminate\Support\Facades\DB;

class RespondToDiscussionRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        EnsureNotAlreadyPartOfDiscussionAction::new()->execute(
            CreateRequestDTO::new()
                ->fromArray([
                    'to' => $requestResponseDTO->request->to,
                    'for' => $requestResponseDTO->request->for,
                ])
        );

        // Locking the request row and re-checking its status inside the lock closes the same
        // double-submission race SCRUM-80 fixed for group therapy membership requests: two
        // concurrent responses to the same request both queue on this lock, so the second one
        // always observes the first's committed status update and just no-ops instead of
        // attaching the counsellor a second time (SCRUM-91). $accepted only reflects whether
        // *this* call performed the transition, so a no-op call never re-sends the
        // notification/broadcast.
        [$request, $accepted] = DB::transaction(function () use ($requestResponseDTO) {
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

            if ($request->status == RequestStatusEnum::accepted->value) {
                $request->for->counsellors()->attach($request->to->id);
            }

            return [$request, $request->status == RequestStatusEnum::accepted->value];
        });

        if ($accepted) {
            $request->from->notify(
                new DiscussionInclusionNotification($request->to, $request->for)
            );
            broadcast(new DiscussionRequestResponseEvent($request));
        }

        return $request;
    }
}
