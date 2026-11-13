<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateProfessionDTO;
use App\Http\Resources\ProfessionResource;
use App\Services\ProfessionService;
use Illuminate\Http\Request;

class ProfessionController extends Controller
{
    public function getProfessions(Request $request) {
        return ProfessionService::new()->getProfessions($request->name ?? '');
    }

    public function createProfession(Request $request) {
        $request->validate([
            'name' => ['required', 'string', 'unique:professions,name'],
            'description' => ['nullable', 'string'],
            'addedbyType' => ['nullable', 'string'],
            'addedbyId' => ['nullable', 'integer'],
        ]);

        $profession = ProfessionService::new()->createProfession(
            CreateProfessionDTO::fromArray([
                'user' => $request->user(),
                'name' => $request->name,
                'description' => $request->description,
                'addedby' => GetModelWithModelNameAndIdAction::new()->execute(
                    $request->addedbyType,
                    $request->addedbyId,
                )
            ])
        );

        return response()->json([
            'status' => true,
            'profession' => new ProfessionResource($profession)
        ]);
    }
}
