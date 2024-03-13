<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateReligionDTO;
use App\Http\Resources\ReligionResource;
use App\Services\ReligionService;
use Illuminate\Http\Request;

class ReligionController extends Controller
{
    public function getReligions(Request $request) {
        return ReligionService::new()->getReligions($request->name ?? '');
    }

    public function createReligion(Request $request) {
        $request->validate([
            'name' => ['required', 'string', 'unique:religions,name'],
            'about' => ['nullable', 'string'],
            'addedbyType' => ['nullable', 'string'],
            'addedbyId' => ['nullable', 'integer'],
        ]);

        $religion = ReligionService::new()->createReligion(
            CreateReligionDTO::fromArray([
                'user' => $request->user(),
                'name' => $request->name,
                'about' => $request->about,
                'addedby' => GetModelWithModelNameAndIdAction::new()->execute(
                    $request->addedbyType,
                    $request->addedbyId,
                )
            ])
        );

        return response()->json([
            'status' => true,
            'religion' => new ReligionResource($religion)
        ]);
    }
}
