<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateLanguageDTO;
use App\Http\Resources\LanguageResource;
use App\Services\LanguageService;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    public function getLanguages(Request $request) {
        return LanguageService::new()->getLanguages($request->name ?? '');
    }

    public function createLanguage(Request $request) {
        $request->validate([
            'name' => ['required', 'string', 'unique:languages,name'],
            'about' => ['nullable', 'string'],
        ]);

        $language = LanguageService::new()->createLanguage(
            CreateLanguageDTO::fromArray([
                'user' => $request->user(),
                'name' => $request->name,
                'about' => $request->about,
                'addedby' => GetModelWithModelNameAndIdAction::new()->execute(
                    $request->addedby_type,
                    $request->addedby_id,
                )
            ])
        );

        return response()->json([
            'status' => true,
            'language' => new LanguageResource($language)
        ]);
    }
}
