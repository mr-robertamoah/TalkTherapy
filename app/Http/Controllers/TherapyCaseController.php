<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateCaseDTO;
use App\Http\Resources\TherapyCaseResource;
use App\Models\TherapyCase;
use App\Services\TherapyCaseService;
use Illuminate\Http\Request;

class TherapyCaseController extends Controller
{
    public function getCases(Request $request) {
        return TherapyCaseService::new()->getCases($request->name ?? '');
    }

    public function createCase(Request $request) {
        $request->validate([
            'name' => ['required', 'string', 'unique:therapy_cases,name'],
            'description' => ['nullable', 'string'],
            'addedbyType' => ['nullable', 'string'],
            'addedbyId' => ['nullable', 'integer'],
        ]);

        $therapyCase = TherapyCaseService::new()->createCase(
            CreateCaseDTO::fromArray([
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
            'case' => new TherapyCaseResource($therapyCase)
        ]);
    }
}
