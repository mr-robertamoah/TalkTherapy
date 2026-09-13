<?php

namespace App\Http\Controllers;

use App\Actions\Counsellor\EnsureCanDeleteCounsellorAction;
use App\Actions\EnsureNameStaysRetrievableAction;
use App\Actions\User\EnsureDobChangeIsAllowedAction;
use App\Actions\User\GetCounsellorCreationStepOfUserAction;
use App\Actions\User\UpdateUserAvatarAction;
use App\DTOs\CheckNameRetrievabilityDTO;
use App\DTOs\DeleteCounsellorDTO;
use App\DTOs\EnsureDobChangeIsAllowedDTO;
use App\DTOs\UpdateUserAvatarDTO;
use App\Exceptions\CannotDeleteCounsellorException;
use App\Exceptions\DobChangeRequiresApprovalException;
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
            // TT-4.10e/SCRUM-294: flashed by update() below when a dob edit was held for
            // guardian/admin approval instead of applied immediately -- only present on the one
            // page load immediately after that redirect (standard Laravel flash semantics).
            'dobChangePendingApproval' => (bool) session('dobChangePendingApproval'),
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

        $newDob = $request->dob ?: null;
        $dobChangeRequiresApproval = false;

        // TT-4.10c/SCRUM-292: dob is deliberately excluded from the direct fill()/save() below
        // when this throws -- everything else the user submitted in the same form still saves
        // immediately (a pending guardian/admin approval for the dob specifically shouldn't hold
        // an unrelated name/email/gender/country edit hostage). EnsureDobChangeIsAllowedAction has
        // already created the pending approval Request by the time this throws.
        try {
            EnsureDobChangeIsAllowedAction::new()->execute(
                EnsureDobChangeIsAllowedDTO::new()->fromArray([
                    'user' => $request->user(),
                    'actor' => $request->user(),
                    'newDob' => $newDob,
                ])
            );
        } catch (DobChangeRequiresApprovalException) {
            $dobChangeRequiresApproval = true;
        }

        $fillData = $request->validated();
        $fillData['dob'] = $dobChangeRequiresApproval ? $request->user()->dob : $newDob;

        $request->user()->fill($fillData);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.show')->with(
            $dobChangeRequiresApproval ? ['dobChangePendingApproval' => true] : []
        );
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
