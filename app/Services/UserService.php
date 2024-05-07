<?php

namespace App\Services;

use App\Actions\EnsureIsAdminAction;
use App\Actions\EnsureIsSuperAdminAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\EnsureUserExistsAction;
use App\Actions\User\UpdateUserAction;
use App\DTOs\GetModelsForAdminDTO;
use App\DTOs\UpdateUserDTO;
use App\Enums\PaginationEnum;
use App\Http\Resources\AdminUserResource;
use App\Models\User;

class UserService extends Service
{
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
}