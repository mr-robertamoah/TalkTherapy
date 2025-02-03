<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;

class CreateRequestAction extends Action
{
    public function execute(CreateRequestDTO $createRequestDTO)
    {
        $request = $createRequestDTO->from->sentRequests()->create([
            'data' => $createRequestDTO->data,
            'type' => $createRequestDTO->type
        ]);

        $request->to()->associate($createRequestDTO->to);

        $request->for()->associate($createRequestDTO->for);

        $request->from()->associate($createRequestDTO->from);

        $request->save();

        return $request->refresh();
    }
}