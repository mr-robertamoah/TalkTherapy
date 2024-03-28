<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\DTOs\CreateRequestDTO;
use App\DTOs\VerifyCounsellorDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\RequestTypeEnum;
use App\Events\CounsellorVerificationRequestSentEvent;
use App\Models\User;
use App\Services\RequestService;

class CreateCounsellorVerificationRequestAction extends Action
{
    public function execute(VerifyCounsellorDTO $verifyCounsellorDTO)
    {
        $request = RequestService::new()->createRequest(
            CreateRequestDTO::new()->fromArray([
                'for' => $verifyCounsellorDTO->counsellor,
                'from' => $verifyCounsellorDTO->counsellor,
                'to' => User::query()->whereHas('administrator', function ($query) {
                    $query->where('type', AdministratorTypeEnum::super->value);
                })->first(),
                'data' => [
                    'nationalIdLicense' => $verifyCounsellorDTO->nationalIdLicense->id,
                    'otherLicense' => $verifyCounsellorDTO->otherLicense->id,
                ],
                'type' => RequestTypeEnum::counsellor->value
            ])
        );

        // TODO CounsellorVerificationRequestSentEvent::broadcast();
        return $request;
    }
}