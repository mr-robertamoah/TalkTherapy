<?php

namespace App\Services;

use App\Actions\Counsellor\CheckVerificationRequestRequirmentAction;
use App\Actions\Counsellor\CreateCounsellorAction;
use App\Actions\Counsellor\CreateCounsellorVerificationRequestAction;
use App\Actions\Counsellor\DeleteCounsellorAction;
use App\Actions\Counsellor\EnsureCanCreateCounsellorAction;
use App\Actions\Counsellor\EnsureCanDeleteCounsellorAction;
use App\Actions\Counsellor\EnsureCanUpdateCounsellorAction;
use App\Actions\Counsellor\EnsureCounsellorDoesNotHavePendingVerificationRequestAction;
use App\Actions\Counsellor\EnsureCounsellorExistsAction;
use App\Actions\Counsellor\EnsureDataAdequacyAction;
use App\Actions\Counsellor\EnsureDataValidityAction;
use App\Actions\Counsellor\EnsureUrlSignatureIsValidAction;
use App\Actions\Counsellor\EnsureUserCanBecomeCounsellorAction;
use App\Actions\Counsellor\EnsureVerificationRequestDataIsValidAction;
use App\Services\LicenseService;
use App\Actions\Counsellor\UpdateCounsellorAction;
use App\Actions\Counsellor\VerifyEmailAction;
use App\Actions\EnsureNameStaysRetrievableAction;
use App\DTOs\CheckNameRetrievabilityDTO;
use App\DTOs\CreateCounsellorDTO;
use App\DTOs\CreateLicenseDTO;
use App\DTOs\DeleteCounsellorDTO;
use App\DTOs\GetCounsellorsForAdminDTO;
use App\DTOs\GetCounsellorStatsForAdminDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\DTOs\VerifyCounsellorDTO;
use App\Enums\ConstantsEnum;
use App\Enums\PaginationEnum;
use App\Http\Resources\AdminCounsellorResource;
use App\Http\Resources\AdminCounsellorStatsResource;
use App\Models\Counsellor;
use App\Models\LicensingAuthority;
use App\Models\Star;
use App\Models\User;
use App\Notifications\VerifyCounsellorEmailNotification;
use Illuminate\Http\Request;

class CounsellorService extends Service
{
    public function verifyEmail(UpdateCounsellorDTO $updateCounsellorDTO)
    {
        EnsureCounsellorExistsAction::new()->execute($updateCounsellorDTO);

        EnsureUrlSignatureIsValidAction::new()->execute($updateCounsellorDTO);

        VerifyEmailAction::new()->execute($updateCounsellorDTO);
    }

    public function sendVerificationEmail(UpdateCounsellorDTO $updateCounsellorDTO)
    {
        EnsureCounsellorExistsAction::new()->execute($updateCounsellorDTO);

        EnsureCanUpdateCounsellorAction::new()->execute($updateCounsellorDTO);

        $updateCounsellorDTO->counsellor->notify(new VerifyCounsellorEmailNotification());
    }

    public function getCounsellors(User $user, String|null $name)
    {
        $query = Counsellor::query();

        $query->when($name, function($query) use ($name) {
            $query->whereName($name);
        });
        
        $query->whereNotUser($user);
        $query->withCount('stars');
        $query->orderBy('stars_count');

        return $query->paginate(PaginationEnum::pagination->value);
    }

    public function getCounsellorData(): array
    {
        $data = [
            'loadedCases' => TherapyCaseService::new()->getCases(),
            'loadedLanguages' => LanguageService::new()->getLanguages(),
            'loadedReligions' => ReligionService::new()->getReligions(),
            'loadedProfessions' => ProfessionService::new()->getProfessions(),
        ];

        return $data;
    }

    public function createCounsellor(CreateCounsellorDTO $createCounsellorDTO): Counsellor
    {
        $createCounsellorDTO = EnsureDataAdequacyAction::new()->execute($createCounsellorDTO);

        EnsureCanCreateCounsellorAction::new()->execute($createCounsellorDTO);

        EnsureUserCanBecomeCounsellorAction::new()->execute($createCounsellorDTO);

        return CreateCounsellorAction::new()->execute($createCounsellorDTO);
    }

    public function updateCounsellor(UpdateCounsellorDTO $updateCounsellorDTO): Counsellor
    {
        EnsureCounsellorExistsAction::new()->execute($updateCounsellorDTO);

        EnsureCanUpdateCounsellorAction::new()->execute($updateCounsellorDTO);

        EnsureNameStaysRetrievableAction::new()->execute(
            CheckNameRetrievabilityDTO::new()->fromArray([
                'newName' => $updateCounsellorDTO->name,
                'changing' => 'counsellor',
                'user' => $updateCounsellorDTO->user,
            ])
        );

        EnsureDataValidityAction::new()->execute($updateCounsellorDTO);

        return UpdateCounsellorAction::new()->execute($updateCounsellorDTO);
    }

    public function deleteCounsellor(DeleteCounsellorDTO $deleteCounsellorDTO)
    {
        EnsureCounsellorExistsAction::new()->execute($deleteCounsellorDTO);

        EnsureCanDeleteCounsellorAction::new()->execute($deleteCounsellorDTO);

        return DeleteCounsellorAction::new()->execute($deleteCounsellorDTO);
    }

    public function verifyCounsellor(VerifyCounsellorDTO $verifyCounsellorDTO)
    {
        EnsureCounsellorExistsAction::new()->execute($verifyCounsellorDTO);
        
        EnsureCounsellorDoesNotHavePendingVerificationRequestAction::new()->execute($verifyCounsellorDTO);

        EnsureCanUpdateCounsellorAction::new()->execute($verifyCounsellorDTO);

        CheckVerificationRequestRequirmentAction::new()->execute($verifyCounsellorDTO);

        EnsureVerificationRequestDataIsValidAction::new()->execute($verifyCounsellorDTO);

        $licenseSerivce = LicenseService::new();
        
        $nationalIdLicense = $licenseSerivce->createLicense(
            CreateLicenseDTO::new()->fromArray([
                'addedby' => $verifyCounsellorDTO->user->isAdmin() 
                    ? $verifyCounsellorDTO->user 
                    : $verifyCounsellorDTO->counsellor,
                'for' => $verifyCounsellorDTO->counsellor,
                'file' => $verifyCounsellorDTO->nationalIdFile,
                'number' => $verifyCounsellorDTO->nationalIdNumber,
                'licensingAuthority' => LicensingAuthority::query()
                    ->where('name', ConstantsEnum::nationalId->value)->first()
            ])
        );
        
        $otherLicense = $licenseSerivce->createLicense(
            CreateLicenseDTO::new()->fromArray([
                'addedby' => $verifyCounsellorDTO->user->isAdmin() 
                    ? $verifyCounsellorDTO->user 
                    : $verifyCounsellorDTO->counsellor,
                'for' => $verifyCounsellorDTO->counsellor,
                'file' => $verifyCounsellorDTO->licenseFile,
                'number' => $verifyCounsellorDTO->licenseNumber,
                'licensingAuthority' => LicensingAuthority::find($verifyCounsellorDTO->licensingAuthorityId)
            ])
        );

        $verifyCounsellorDTO = $verifyCounsellorDTO->withOtherLicense($otherLicense);
        $verifyCounsellorDTO = $verifyCounsellorDTO->withNationalIdLicense($nationalIdLicense);

        return CreateCounsellorVerificationRequestAction::new()->execute($verifyCounsellorDTO);
    }

    public function geCounsellorsForAdmin(GetCounsellorsForAdminDTO $getCounsellorsForAdminDTO)
    {
        if (is_null($getCounsellorsForAdminDTO->user) || $getCounsellorsForAdminDTO->user?->isNotAdmin()) {
            return [];
        }

        $query = Counsellor::query();

        if ($getCounsellorsForAdminDTO->filterType == 'name') {
            $query
                ->where($getCounsellorsForAdminDTO->filterType, 'LIKE', "%{$getCounsellorsForAdminDTO->filterValue}%")
                ->orWhereHas('user', function ($q) use ($getCounsellorsForAdminDTO) {
                    $q
                        ->where('firstName', 'LIKE', "%{$getCounsellorsForAdminDTO->filterValue}%")
                        ->orWhere('lastName', 'LIKE', "%{$getCounsellorsForAdminDTO->filterValue}%")
                        ->orWhere('otherNames', 'LIKE', "%{$getCounsellorsForAdminDTO->filterValue}%")
                        ->orWhere('username', 'LIKE', "%{$getCounsellorsForAdminDTO->filterValue}%");
                });
        }

        return AdminCounsellorResource::collection($query->paginate(
            PaginationEnum::preferencesPagination->value
        ));
    }

    public function getCounsellorStats(GetCounsellorStatsForAdminDTO $getCounsellorStatsForAdminDTO)
    {
        if (is_null($getCounsellorStatsForAdminDTO->user) || $getCounsellorStatsForAdminDTO->user?->isNotAdmin()) {
            return [];
        }

        return new AdminCounsellorStatsResource($getCounsellorStatsForAdminDTO->counsellor);
    }

    public function getLeadingCounsellorsForCurrentMonth()
    {
        $query = User::query();

        $query
            ->with(['stars', 'counsellor'])
            ->withCount(['stars' => function ($q) {
                $q->whereWithinCurrentMonth();
            }])
            ->has('counsellor');

        $query->orderBy('stars_count', 'desc');
        $query->limit(5);
        
        return $query->get();
    }

    public function getBestCounsellorsForPreviousMonth()
    {
        $query = User::query();

        $query
            ->with(['stars', 'counsellor'])
            ->withCount(['stars' => function ($q) {
                $q->whereWithinPreviousMonth();
            }])
            ->has('counsellor');

        $query->orderBy('stars_count', 'desc');
        $query->limit(5);
        
        return $query->get();
    }

    public function getRandomCounsellors(?User $user)
    {
        $query = Counsellor::query();

        $query->when($user, function ($query) use ($user) {
            $query->whereNot('user_id', $user->id);
        });

        $query->inRandomOrder();

        return $query->paginate(PaginationEnum::preferencesPagination->value);
    }
}