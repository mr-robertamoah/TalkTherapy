<?php

namespace App\Actions\Request;

use App\Actions\Action;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\License;

class RespondToCounsellorVerificationRequestAction extends Action
{
    public function execute(RequestResponseDTO $requestResponseDTO)
    {
        $nationalIdLicense = License::find($requestResponseDTO->request->data['nationalIdLicense']);

        $nationalIdLicense->validate();

        $otherLicense = License::find($requestResponseDTO->request->data['otherLicense']);

        $otherLicense->validate();

        if ($otherLicense->licensingAuthority->isNotValidated())
            $otherLicense->licensingAuthority->validate();

        $requestResponseDTO->request->update([
            'status' => is_null($requestResponseDTO->response)
                ? RequestStatusEnum::rejected->value
                : strtoupper($requestResponseDTO->response)
        ]);
        
        $request = $requestResponseDTO->request->refresh();

        if ($request->status == RequestStatusEnum::accepted->value)
            $request->from->verify();
        
        return $request;
    }
}