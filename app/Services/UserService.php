<?php

namespace App\Services;

use App\Actions\EnsureIsAdminAction;
use App\Actions\EnsureIsSuperAdminAction;
use App\Actions\Suspension\EnsureCanDeleteOwnAccountWhileSuspendedAction;
use App\Actions\User\CreateGuardianshipRequestAction;
use App\Actions\User\DeleteGuardianshipAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\EnsureCanUpdateGuardianshipAction;
use App\Actions\User\EnsureGuardianshipExistsAction;
use App\Actions\User\EnsureRequestDataIsValidAction;
use App\Actions\User\EnsureUserCanBeGuardianAction;
use App\Actions\User\EnsureUserDoesNotHaveAGuardianshipRequestAction;
use App\Actions\User\EnsureUserExistsAction;
use App\Actions\User\EnsureUserIsNotAlreadyGuardianAction;
use App\Actions\User\SubmitAgeVerificationAction;
use App\Actions\User\UpdateUserAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\GetGuardianshipDTO;
use App\DTOs\GetModelsForAdminDTO;
use App\DTOs\GetUsersDTO;
use App\DTOs\SubmitAgeVerificationDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\PaginationEnum;
use App\Enums\SuspensionScopeEnum;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\GuardianshipResource;
use App\Http\Resources\UserMiniResource;
use App\Models\Guardianship;
use App\Models\User;
use App\Support\AdminSearch;

class UserService extends Service
{
    public function sendGuardianshipRequest(CreateRequestDTO $createRequestDTO)
    {
        EnsureRequestDataIsValidAction::new()->execute($createRequestDTO);

        EnsureUserIsNotAlreadyGuardianAction::new()->execute($createRequestDTO);

        EnsureUserDoesNotHaveAGuardianshipRequestAction::new()->execute($createRequestDTO);

        EnsureUserCanBeGuardianAction::new()->execute($createRequestDTO);

        return CreateGuardianshipRequestAction::new()->execute($createRequestDTO);
    }

    // TT-4.11b/SCRUM-303
    public function submitAgeVerification(SubmitAgeVerificationDTO $submitAgeVerificationDTO)
    {
        return SubmitAgeVerificationAction::new()->execute($submitAgeVerificationDTO);
    }

    public function deleteGuardianship(GetGuardianshipDTO $getGuardianshipDTO)
    {
        EnsureUserExistsAction::new()->execute($getGuardianshipDTO->user);

        EnsureGuardianshipExistsAction::new()->execute($getGuardianshipDTO);

        EnsureCanUpdateGuardianshipAction::new()->execute($getGuardianshipDTO);

        DeleteGuardianshipAction::new()->execute($getGuardianshipDTO);
    }

    public function updateSettings(UpdateUserDTO $updateUserDTO): User
    {
        $settings = $updateUserDTO->user->settings ? $updateUserDTO->user->settings : [];
        $updateUserDTO->user->settings = [...$settings, ...$updateUserDTO->settings];

        $updateUserDTO->user->save();

        return $updateUserDTO->user->refresh();
    }

    public function updateUserByAdmin(UpdateUserDTO $updateUserDTO): User
    {
        EnsureIsAdminAction::new()->execute($updateUserDTO);

        EnsureUserExistsAction::new()->execute($updateUserDTO->updatedUser);

        $user = UpdateUserAction::new()->execute($updateUserDTO);

        return $user;
    }

    public function deleteUserByAdmin(UpdateUserDTO $updateUserDTO): void
    {
        EnsureIsSuperAdminAction::new()->execute($updateUserDTO);

        EnsureUserExistsAction::new()->execute($updateUserDTO->updatedUser);

        // SCRUM-363 (security review): not a way round the block on deleting your own account while
        // suspended.
        if ($updateUserDTO->updatedUser->is($updateUserDTO->user)) {
            EnsureCanDeleteOwnAccountWhileSuspendedAction::new()->execute($updateUserDTO->user);
        }

        DeleteUserAction::new()->execute($updateUserDTO);
    }

    public function getUsersForAdmin(GetModelsForAdminDTO $getModelsForAdminDTO)
    {
        if (is_null($getModelsForAdminDTO->user) || $getModelsForAdminDTO->user?->isNotAdmin()) {
            return [];
        }

        // SCRUM-193: avatarFile eager-loaded -- AdminUserResource shows each user's avatar.
        $query = User::query()->with(['administrator', 'avatarFile']);

        $query->whereNot('id', $getModelsForAdminDTO->user->id);

        $query->when($getModelsForAdminDTO->name, fn ($query) => $query->whereContains(['firstName', 'lastName', 'otherNames'], $getModelsForAdminDTO->name));
        $query->when($getModelsForAdminDTO->username, fn ($query) => $query->whereContains('username', $getModelsForAdminDTO->username));
        // SCRUM-390: names, username and email (all shown on the page).
        $query->when($getModelsForAdminDTO->search, fn ($query, $term) => AdminSearch::person($query, $term, withEmail: true));

        $query->when($getModelsForAdminDTO->age, function ($query) use ($getModelsForAdminDTO) {
            $query->where(function ($query) use ($getModelsForAdminDTO) {
                $query
                    ->whereNotNull('dob')
                    ->where('dob', '<', now()->subYears($getModelsForAdminDTO->age));
            });
        });

        return AdminUserResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        )->withQueryString());
    }

    public function getUsers(GetUsersDTO $getUsersDTO)
    {
        // SCRUM-363: an empty search used to list everyone.
        if (trim((string) $getUsersDTO->like) === '') {
            return [];
        }

        $query = User::query();

        if ($getUsersDTO->user) {
            $query->whereNot('id', $getUsersDTO->user->id);
        }

        // Grouped (whereContains), so the ORs can't escape the "not yourself" filter above.
        $query->whereContains(['username', 'firstName', 'lastName', 'otherNames'], trim($getUsersDTO->like));

        // SCRUM-359: this feeds the guardian and organisation invite pickers.
        $query->whereDoesntHave('suspensions', fn ($q) => $q->active()->where('scope', SuspensionScopeEnum::account->value));

        return UserMiniResource::collection($query->paginate(PaginationEnum::preferencesPagination->value));
    }

    public function getGuardianship(User $user)
    {
        $query = Guardianship::query();

        $query->where('ward_id', $user->id);
        $query->orWhere(function ($query) use ($user) {
            $query->where('guardian_id', $user->id);
        });

        return GuardianshipResource::collection(
            $query->latest()->get()
        );
    }
}
