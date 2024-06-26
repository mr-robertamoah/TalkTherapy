<?php

namespace App\Services;

use App\Actions\Request\EnsureRequestExistsAction;
use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Actions\Request\GetRequestResourceAction;
use App\Actions\Request\RespondToRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\GetVerificationRequestsDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\PaginationEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
use App\Http\Resources\RequestResource;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\User;

class RequestService extends Service
{
    public function getRequests(String $status = '', User $user) {
        $query = Request::query();

        $query->where(function ($query) use ($status, $user) {
            $query->whereFrom($user);
            if ($status) $query->where('status',  $status);
        });

        $query->orWhere(function ($query) use ($status, $user) {
            $query->whereTo($user);
            if ($status) $query->where('status',  $status);
        });

        $counsellor = $user->counsellor;
        $query->when($counsellor, function ($query) use ($counsellor, $status) {

            $query->orWhere(function ($query) use ($status, $counsellor) {
                $query->whereFrom($counsellor);
                if ($status) $query->where('status',  $status);
            });
            $query->orWhere(function ($query) use ($status, $counsellor) {
                $query->whereTo($counsellor);
                if ($status) $query->where('status',  $status);
            });
        });

        if ($status) $query->where('status',  $status);

        return RequestResource::collection($query->latest()->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function getVerificationRequestsForCounsellors(GetVerificationRequestsDTO $getVerificationRequestsDTO)
    {
        if (is_null($getVerificationRequestsDTO->user) || $getVerificationRequestsDTO->user?->isNotAdmin()) {
            return [];
        }

        $query = Request::query();
        
        $query->where('type',  RequestTypeEnum::counsellor->value);

        if ($getVerificationRequestsDTO->filterType == 'validated')
            $query->where($getVerificationRequestsDTO->filterType, $getVerificationRequestsDTO->filterValue);

        return AdminCounsellorVerificationRequestResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function respondToRequest(RequestResponseDTO $requestResponseDTO)
    {
        EnsureRequestExistsAction::new()->execute($requestResponseDTO);
        
        EnsureUserCanRespondToRequestAction::new()->execute($requestResponseDTO);

        $request = RespondToRequestAction::new()->execute($requestResponseDTO);

        // TODO dispatch event
        return GetRequestResourceAction::new()->execute($request);
    }
}