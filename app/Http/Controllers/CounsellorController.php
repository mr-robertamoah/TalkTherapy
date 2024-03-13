<?php

namespace App\Http\Controllers;

use App\Actions\Counsellor\GetCounsellorAccountForProfileViewAction;
use App\Actions\User\GetCounsellorCreationStepOfUserAction;
use App\DTOs\CreateCounsellorDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\Http\Requests\UpdateCounsellorRequest;
use App\Http\Resources\CounsellorMiniResource;
use App\Http\Resources\CounsellorResource;
use App\Models\Counsellor;
use App\Services\CounsellorService;
use App\Services\LanguageService;
use App\Services\ProfessionService;
use App\Services\ReligionService;
use App\Services\TherapyCaseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class CounsellorController extends Controller
{
    public function createCounsellor(Request $request)
    {
        try {
            $request->validate([
                'name' => ['nullable', 'string', 'max:255', Rule::requiredIf((bool) !$request->user()->name)],
                'about' => ['nullable', 'string',]
            ]);

            $counsellor = CounsellorService::new()->createCounsellor(
                CreateCounsellorDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'about' => $request->about,
                ])
            );
            return response()->json([
                'status' => true,
                'counsellor' => new CounsellorMiniResource($counsellor)
            ]);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            throw new Exception($message);
        }
    }

    public function show(Request $request)
    {
        try {
            $counsellor = GetCounsellorAccountForProfileViewAction::new()->execute($request->counsellorId);

            $counsellorResource = new CounsellorResource($counsellor);

            $counsellorResource->withoutWrapping();

            $data = [
                'counsellor' => $counsellorResource,
                'counsellorCreationStep' => GetCounsellorCreationStepOfUserAction::new()->execute($counsellor->user)
            ];

            if ($counsellor->user->is($request->user())) {
                $data = array_merge($data, CounsellorService::new()->getCounsellorData());
            }

            return Inertia::render('Profile/Counsellor/Show', $data);
        } catch (Throwable $th) {
            return Redirect::route('home')->with('message', $th->getMessage());
        }
    }

    public function updateCounsellor(UpdateCounsellorRequest $request)
    {
        try {
            CounsellorService::new()->updateCounsellor(
                UpdateCounsellorDTO::new()->fromArray([
                    'avatar' => $request->file('avatar'),
                    'cover' => $request->file('cover'),
                    'name' => $request->name,
                    'email' => $request->email,
                    'contactVisible' => $request->contactVisible,
                    'about' => $request->about,
                    'phone' => $request->phone,
                    'selectedCases' => $request->selectedCases,
                    'selectedLanguages' => $request->selectedLanguages,
                    'selectedReligions' => $request->selectedReligions,
                    'professionId' => $request->professionId,
                    'user' => $request->user(),
                    'counsellor' => Counsellor::find($request->counsellorId)
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            throw new Exception($message);
        }
    }
}
