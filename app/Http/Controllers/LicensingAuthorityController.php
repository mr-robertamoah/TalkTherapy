<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateLicensingAuthorityDTO;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Http\Requests\CreateLicensingAuthorityRequest;
use App\Http\Resources\LicensingAuthorityResource;
use App\Services\LicensingAuthorityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LicensingAuthorityController extends Controller
{
    public function getLicensingAuthorities(Request $request)
    {
        return LicensingAuthorityResource::collection(
            LicensingAuthorityService::new()->getOtherLicensingAuthorities($request->name)
        );
    }

    public function createLicensingAuthority(CreateLicensingAuthorityRequest $request) {

        $licensingAuthority = LicensingAuthorityService::new()->createLicensingAuthority(
            CreateLicensingAuthorityDTO::fromArray([
                'user' => $request->user(),
                'name' => $request->name,
                'about' => $request->about,
                'type' => $request->type,
                'licenseType' => $request->licenseType,
                'country' => $request->country,
                'email' => $request->email,
                'phone' => $request->phone,
                'other' => $request->other,
                'addedby' => GetModelWithModelNameAndIdAction::new()->execute(
                    $request->addedbyType,
                    $request->addedbyId,
                )
            ])
        );

        return response()->json([
            'status' => true,
            'licensingAuthority' => new LicensingAuthorityResource($licensingAuthority)
        ]);
    }
}
