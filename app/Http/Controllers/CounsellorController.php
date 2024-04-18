<?php

namespace App\Http\Controllers;

use App\Actions\Counsellor\GetCounsellorAccountForProfileViewAction;
use App\Actions\User\GetCounsellorCreationStepOfUserAction;
use App\DTOs\CreateCounsellorDTO;
use App\DTOs\DeleteCounsellorDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\DTOs\VerifyCounsellorDTO;
use App\Http\Requests\UpdateCounsellorRequest;
use App\Http\Requests\VerifyCounsellorRequest;
use App\Http\Resources\AssistanceRequestCounsellorResource;
use App\Http\Resources\CounsellorMiniResource;
use App\Http\Resources\CounsellorResource;
use App\Http\Resources\StarredCounsellorResource;
use App\Models\Counsellor;
use App\Services\CounsellorService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class CounsellorController extends Controller
{
    public function verifyEmail(Request $request)
    {
        try {
            CounsellorService::new()->verifyEmail(
                UpdateCounsellorDTO::new()->fromArray([
                    'counsellor' => Counsellor::find($request->counsellorId),
                    'request' => $request,
                ])
            );        

            return redirect()->route('counsellor.show', ['counsellorId' => $request->counsellorId]);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
            ds($th);
            return redirect()->route('counsellor.show', ['counsellorId' => $request->counsellorId])->withErrors('message', $message);
        }
    }
    
    public function getRandomCounsellors(Request $request)
    {
        try {
            $counsellors = CounsellorService::new()->getRandomCounsellors($request->user());        

            return StarredCounsellorResource::collection($counsellors);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
            ds($th);
            throw new Exception($message);
        }
    }

    public function sendVerificationEmail(Request $request)
    {
        CounsellorService::new()->sendVerificationEmail(
            UpdateCounsellorDTO::new()->fromArray([
                'counsellor' => Counsellor::find($request->counsellorId),
                'user' => $request->user(),
            ])
        );        

        return redirect()->back()->with('message', 'verification email sent.');
    }
        
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

            $page = Inertia::render('Profile/Counsellor/Show', $data);

            $message = session('message');

            if ($message) $page->with('message', $message);

            return $page;
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
                    'deleteCover' => $request->deleteCover,
                    'deleteAvatar' => $request->deleteAvatar,
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

    public function deleteCounsellor(Request $request)
    {
        try {
            CounsellorService::new()->deleteCounsellor(
                DeleteCounsellorDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => Counsellor::find($request->counsellorId)
                ])
            );

            return Redirect::route('profile.show');
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            throw new Exception($message);
        }
    }
    
    public function getCounsellors(Request $request)
    {
        return AssistanceRequestCounsellorResource::collection(
            CounsellorService::new()->getCounsellors($request->user(), $request->name)
        );
    }

    public function verifyCounsellor(VerifyCounsellorRequest $request)
    {
        try {
            CounsellorService::new()->verifyCounsellor(
                VerifyCounsellorDTO::new()->fromArray([
                    'licenseFile' => $request->file('licenseFile'),
                    'nationalIdFile' => $request->file('nationalIdFile'),
                    'licenseNumber' => $request->licenseNumber,
                    'nationalIdNumber' => $request->nationalIdNumber,
                    'licensingAuthorityId' => $request->licensingAuthorityId,
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
