<?php

namespace App\Actions\Discussion;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Exceptions\DiscussionException;
use App\Models\Request;

class EnsureDoesNotHaveDiscussionRequestAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        if (
            !Request::query()
                ->wherePending()
                ->whereTo($createRequestDTO->to)
                ->whereFor($createRequestDTO->for)
                ->whereType(RequestTypeEnum::discussion->value)
                ->exists()
        ) return;

        throw new DiscussionException("The counsellor you are trying to send a request is alreaady has a request for the same discussion.", 422);
    }
}