<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\Actions\Discussion\EnsureNotAlreadyPartOfDiscussionAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Notifications\DiscussionInclusionNotification;

class RespondToDiscussionRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        EnsureNotAlreadyPartOfDiscussionAction::new()->execute(
            CreateRequestDTO::new()->fromArray(['to' => $requestResponseDTO->request->to])
        );
        
        $requestResponseDTO->request->update([
            'status' => is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response)
        ]);
        
        $request = $requestResponseDTO->request->refresh();

        if ($request->status == RequestStatusEnum::accepted->value) {
            $request->for->counsellors()->attach($request->to->id);
            $request->from->notify(
                new DiscussionInclusionNotification($request->to, $request->for)
            );
        }
        
        return $request;
    }
}