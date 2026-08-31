<?php

namespace App\Http\Controllers;

use App\Actions\Counsellor\EnsureCanDeleteCounsellorAction;
use App\Actions\EnsureNameStaysRetrievableAction;
use App\Actions\User\GetCounsellorCreationStepOfUserAction;
use App\Actions\User\UpdateUserAvatarAction;
use App\DTOs\CheckNameRetrievabilityDTO;
use App\DTOs\DeleteCounsellorDTO;
use App\DTOs\UpdateUserAvatarDTO;
use App\Exceptions\CannotDeleteCounsellorException;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\UpdateUserAvatarRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Profile/Show', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'counsellorCreationStep' => GetCounsellorCreationStepOfUserAction::new()->execute($request->user()),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        EnsureNameStaysRetrievableAction::new()->execute(
            CheckNameRetrievabilityDTO::new()->fromArray([
                'newName' => constructName(
                    $request->firstName,
                    $request->lastName,
                    $request->otherNames,
                ),
                'user' => $request->user(),
                'changing' => 'user',
            ])
        );

        $request->user()->fill(array_merge(
            $request->validated(),
            ['dob' => $request->dob ?: null]
        ));

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.show');
    }

    /**
     * Update (or delete) the authenticated user's own avatar.
     */
    public function updateAvatar(UpdateUserAvatarRequest $request): RedirectResponse
    {
        UpdateUserAvatarAction::new()->execute(
            UpdateUserAvatarDTO::new()->fromArray([
                'user' => $request->user(),
                'avatar' => $request->file('avatar'),
                'deleteAvatar' => $request->boolean('deleteAvatar'),
            ])
        );

        return Redirect::route('profile.show');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // A counsellor with pending sessions can't just stop being a counsellor (see
        // CounsellorController::deleteCounsellor / EnsureCanDeleteCounsellorAction) -- deleting
        // the whole account shouldn't be a way around that same safeguard, so it's enforced
        // here too before anything is deleted.
        if ($user->counsellor) {
            try {
                EnsureCanDeleteCounsellorAction::new()->execute(
                    DeleteCounsellorDTO::new()->fromArray([
                        'user' => $user,
                        'counsellor' => $user->counsellor,
                    ])
                );
            } catch (CannotDeleteCounsellorException) {
                throw ValidationException::withMessages([
                    'password' => 'You have pending counsellor sessions. Please complete or cancel them before deleting your account.',
                ]);
            }
        }

        DB::transaction(function () use ($user) {
            // Soft-delete the linked Counsellor record too -- otherwise it's left active with a
            // deleted user behind it, and anything that renders that counsellor (random
            // counsellor listings, a therapy's assigned counsellor, etc.) crashes trying to read
            // properties off the now-missing user relationship.
            $user->counsellor?->delete();

            $user->delete();
        });

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
