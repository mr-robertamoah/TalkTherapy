<?php

namespace App\Services;

use App\Actions\EnsureIsAdminAction;
use App\Actions\EnsureIsSuperAdminAction;
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
use App\Actions\User\UpdateUserAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\GetGuardianshipDTO;
use App\DTOs\GetModelsForAdminDTO;
use App\DTOs\GetUsersDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\GuardianshipResource;
use App\Http\Resources\UserMiniResource;
use App\Models\Guardianship;
use App\Models\User;

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

        DeleteUserAction::new()->execute($updateUserDTO);
    }

    public function getUsersForAdmin(GetModelsForAdminDTO $getModelsForAdminDTO)
    {
        if (is_null($getModelsForAdminDTO->user) || $getModelsForAdminDTO->user?->isNotAdmin()) {
            return [];
        }

        $query = User::query();

        $query->whereNot('id', $getModelsForAdminDTO->user->id);

        $query->when($getModelsForAdminDTO->name, function ($query) use ($getModelsForAdminDTO) {
            $query->where(function ($query) use ($getModelsForAdminDTO) {
                $query
                    ->where('firstName', 'LIKE', "%{$getModelsForAdminDTO->name}%")
                    ->orWhere('lastName', 'LIKE', "%{$getModelsForAdminDTO->name}%")
                    ->orWhere('otherNames', 'LIKE', "%{$getModelsForAdminDTO->name}%");
            });
        });
        $query->when($getModelsForAdminDTO->username, function ($query) use ($getModelsForAdminDTO) {
            $query
                ->where('username', 'LIKE', "%{$getModelsForAdminDTO->username}%");
        });

        $query->when($getModelsForAdminDTO->age, function ($query) use ($getModelsForAdminDTO) {
            $query->where(function ($query) use ($getModelsForAdminDTO) {
                $query
                    ->whereNotNull('dob')
                    ->where('dob', '<', now()->subYears($getModelsForAdminDTO->age));
            });
        });

        return AdminUserResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function getUsers(GetUsersDTO $getUsersDTO)
    {
        if (is_null($getUsersDTO->like)) return [];

        $query = User::query();

        if ($getUsersDTO->user)
            $query->whereNot('id', $getUsersDTO->user->id);

        
        $query->where('username', 'LIKE', "%{$getUsersDTO->like}%");
        $query->orWhere(function ($query) use ($getUsersDTO) {
            $query->where('firstName', 'LIKE', "%{$getUsersDTO->like}%");
        });
        $query->orWhere(function ($query) use ($getUsersDTO) {
            $query->where('lastName', 'LIKE', "%{$getUsersDTO->like}%");
        });
        $query->orWhere(function ($query) use ($getUsersDTO) {
            $query->where('otherNames', 'LIKE', "%{$getUsersDTO->like}%");
        });

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