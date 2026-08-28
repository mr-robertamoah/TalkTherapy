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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class CounsellorController extends Controller
{
    // Every lookup below reads $request->route('counsellorId') rather than the magic
    // ->counsellorId property -- see the identical fix/rationale in SessionController
    // (SCRUM-116/SCRUM-130).
    public function verifyEmail(Request $request)
    {
        try {
            CounsellorService::new()->verifyEmail(
                UpdateCounsellorDTO::new()->fromArray([
                    'counsellor' => Counsellor::find($request->route('counsellorId')),
                    'request' => $request,
                ])
            );

            return redirect()->route('counsellor.show', ['counsellorId' => $request->route('counsellorId')]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return redirect()->route('counsellor.show', ['counsellorId' => $request->route('counsellorId')])->withErrors('message', $message);
        }
    }

    public function getRandomCounsellors(Request $request)
    {
        try {
            $counsellors = CounsellorService::new()->getRandomCounsellors($request->user());

            return StarredCounsellorResource::collection($counsellors);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function getCounsellors(Request $request)
    {
        try {
            $counsellors = CounsellorService::new()->getCounsellors($request->user(), $request->name);

            return CounsellorMiniResource::collection($counsellors);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function sendVerificationEmail(Request $request)
    {
        CounsellorService::new()->sendVerificationEmail(
            UpdateCounsellorDTO::new()->fromArray([
                'counsellor' => Counsellor::find($request->route('counsellorId')),
                'user' => $request->user(),
            ])
        );

        return redirect()->back()->with('message', 'verification email sent.');
    }

    public function createCounsellor(Request $request)
    {
        try {
            $request->validate([
                'name' => ['nullable', 'string', 'max:255', Rule::requiredIf((bool) ! $request->user()->name)],
                'about' => ['nullable', 'string'],
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
                'counsellor' => new CounsellorMiniResource($counsellor),
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function show(Request $request)
    {
        try {
            $counsellor = GetCounsellorAccountForProfileViewAction::new()->execute($request->route('counsellorId'));

            $counsellorResource = new CounsellorResource($counsellor);

            $counsellorResource->withoutWrapping();

            $data = [
                'counsellor' => $counsellorResource,
                'counsellorCreationStep' => GetCounsellorCreationStepOfUserAction::new()->execute($counsellor->user),
            ];

            if ($counsellor->user->is($request->user())) {
                $data = array_merge($data, CounsellorService::new()->getCounsellorData());
            }

            $page = Inertia::render('Profile/Counsellor/Show', $data);

            $message = session('message');

            if ($message) {
                $page->with('message', $message);
            }

            return $page;
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::route('home')->with('message', $message);
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
                    'counsellor' => Counsellor::find($request->route('counsellorId')),
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    // No route currently maps to this method (the frontend's `counsellor.delete` reference is
    // itself dead -- see decision-log) -- fixed for consistency with the rest of this file and
    // as defense-in-depth against that route being wired up later without this being revisited.
    public function deleteCounsellor(Request $request)
    {
        try {
            CounsellorService::new()->deleteCounsellor(
                DeleteCounsellorDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => Counsellor::find($request->route('counsellorId')),
                ])
            );

            return Redirect::route('profile.show');
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    public function getRequestCounsellors(Request $request)
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
                    'counsellor' => Counsellor::find($request->route('counsellorId')),
                ])
            );

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }
}
