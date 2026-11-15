<?php

namespace App\Services;

use App\Actions\EnsureIsAdminAction;
use App\Actions\EnsureIsSuperAdminAction;
use App\Actions\Guardianship\EnsureCanHaveNewGuardianAction;
use App\Actions\Suspension\EnsureCanDeleteOwnAccountWhileSuspendedAction;
use App\Actions\User\CreateGuardianshipRequestAction;
use App\Actions\User\DeleteGuardianshipAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\EnsureCanUpdateGuardianshipAction;
use App\Actions\User\EnsureCanUpdateUserByAdminAction;
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
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\HelpRequest;
use App\Models\Session;
use App\Models\User;
use App\Support\AdminSearch;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class UserService extends Service
{
    public function sendGuardianshipRequest(CreateRequestDTO $createRequestDTO)
    {
        EnsureRequestDataIsValidAction::new()->execute($createRequestDTO);

        EnsureCanHaveNewGuardianAction::new()->execute($createRequestDTO->from);

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

        // SCRUM-425: the email, the verified tick and administrators' accounts.
        EnsureCanUpdateUserByAdminAction::new()->execute($updateUserDTO->user, $updateUserDTO->updatedUser, $updateUserDTO->email, $updateUserDTO->emailVerified);

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

        $query = User::query();

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

        $users = $query->paginate(PaginationEnum::preferencesPagination->value)->withQueryString();
        self::loadForAdminRows($users->getCollection());

        return AdminUserResource::collection($users);
    }

    // SCRUM-430: everything a row of the admin users list shows (AdminUserResource), loaded for a page of them at once --
    // and for the one person an admin action sends back, so the row keeps showing the same. SCRUM-193: the avatar;
    // SCRUM-424: a scheduled two-factor reset; the role and whether the account is suspended; and, for the few that are
    // (security review), the suspensions behind it with what Suspension::involves reads, so the resource can hide it
    // from an administrator involved in one -- as the suspensions list does. That check still asks a few questions of
    // its own per suspended row (a therapy's members: TherapyTrait::involvesInOversight), as on the suspensions list
    // -- a page at most; other rows add none.
    public static function loadForAdminRows(EloquentCollection $users): EloquentCollection
    {
        $suspended = fn ($q) => $q->active()->covering(SuspensionScopeEnum::account);

        $users->load(['administrator', 'avatarFile', 'pendingTwoFactorReset'])
            ->loadExists(['counsellor', 'suspensions as account_suspended' => $suspended]);

        $users->where('account_suspended', true)->values()->load(['suspensions' => fn ($q) => $suspended($q)->with([
            'report.addedby' => fn ($morph) => $morph->morphWith([Counsellor::class => ['user']]),
            'report.reportable' => fn ($morph) => $morph->morphWith([Session::class => ['for'], HelpRequest::class => ['user']]),
        ])]);

        return $users;
    }

    public static function forAdminRow(User $user): User
    {
        return self::loadForAdminRows(new EloquentCollection([$user]))->first();
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

        // SCRUM-441: the guardian picker lists only who could be a guardian (EnsureUserCanBeGuardianAction's rule):
        // 18 or over, with a verified email -- never a minor.
        // Only for someone with a date of birth that makes them under 18 (security and code reviews): run with and
        // without it by anyone else -- an adult, or a new account with no date of birth -- it would pick out who is a
        // minor. NoOverflow, so 29 February agrees with User::isAdult().
        if ($getUsersDTO->for === GetUsersDTO::FOR_GUARDIAN && (! $getUsersDTO->user?->dob || $getUsersDTO->user->isAdult())) {
            return [];
        }

        $query->when($getUsersDTO->for === GetUsersDTO::FOR_GUARDIAN, fn ($q) => $q
            ->whereNotNull('email')->whereNotNull('email_verified_at')
            ->whereNotNull('dob')->whereDate('dob', '<=', now()->subYearsNoOverflow(18)->toDateString()));

        // SCRUM-359: this feeds the guardian and organisation invite pickers.
        $query->whereDoesntHave('suspensions', fn ($q) => $q->active()->where('scope', SuspensionScopeEnum::account->value));

        return UserMiniResource::collection($query->paginate(PaginationEnum::preferencesPagination->value));
    }

    public function getGuardianship(User $user)
    {
        $query = Guardianship::query()->with(['guardian', 'ward']);

        $query->where('ward_id', $user->id);
        $query->orWhere(function ($query) use ($user) {
            $query->where('guardian_id', $user->id);
        });

        return GuardianshipResource::collection(
            $query->latest()->get()
        );
    }
}
