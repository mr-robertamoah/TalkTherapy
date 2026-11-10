<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\Exceptions\BadRequestException;
use App\Models\Counsellor;

class GetCounsellorAccountForProfileViewAction extends Action
{
    public function execute(string|int|null $counsellorId)
    {
        if (is_null($counsellorId)) {
            throw new BadRequestException('This counsellor does not exist.', 422);
        }

        $counsellor = Counsellor::query()
            ->where('id', $counsellorId)
            ->with([

            ])
            ->first();

        if (is_null($counsellor)) {
            throw new BadRequestException('This counsellor does not exist.', 422);
        }

        // TODO load relations
        return $counsellor;
    }
}
