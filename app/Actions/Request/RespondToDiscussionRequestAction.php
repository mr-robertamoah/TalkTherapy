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
use Illuminate\Database\UniqueConstraintViolationException;
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
        // attaching the counsellor a second time (SCRUM-91).
        //
        // That alone doesn't close a *different* pair's worth of race: the same counsellor
        // could end up with two separate pending requests for the same discussion (each locks
        // a different Request row, so they don't serialize against each other) -- accepting
        // both, even sequentially, would try to attach the same pivot pair twice. $attached
        // only reflects whether *this* call actually attached, so a redundant-but-accepted
        // second request doesn't duplicate the pivot row or re-send the
        // notification/broadcast. The counsellor_discussion(counsellor_id, discussion_id)
        // unique index (SCRUM-100) is the actual guarantee; the catch below is the fallback for
        // the residual race the request-lock alone can't close.
        [$request, $attached] = DB::transaction(function () use ($requestResponseDTO) {
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

            $attached = $request->status == RequestStatusEnum::accepted->value
                && $this->attachCounsellorIfMissing($request);

            return [$request, $attached];
        });

        if ($attached) {
            $request->from->notify(
                new DiscussionInclusionNotification($request->to, $request->for)
            );
            broadcast(new DiscussionRequestResponseEvent($request));
        }

        return $request;
    }

    private function attachCounsellorIfMissing(Request $request): bool
    {
        if ($request->for->counsellors()->where('counsellor_id', $request->to->id)->exists()) {
            return false;
        }

        try {
            $request->for->counsellors()->attach($request->to->id);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
