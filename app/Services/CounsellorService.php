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
use App\Actions\Counsellor\UpdateCounsellorAction;
use App\Actions\Counsellor\VerifyEmailAction;
use App\Actions\CounsellorVerification\EnsureCounsellorCanRequestVerificationAction;
use App\Actions\EnsureNameStaysRetrievableAction;
use App\Actions\Payout\GetCounsellorPayoutOverviewAction;
use App\Actions\Recognition\GetRecognitionSuggestionsAction;
use App\Actions\Star\CountCounsellorStarsAction;
use App\Actions\Suspension\EnsureCanDeleteOwnAccountWhileSuspendedAction;
use App\DTOs\CheckNameRetrievabilityDTO;
use App\DTOs\CreateCounsellorDTO;
use App\DTOs\CreateLicenseDTO;
use App\DTOs\DeleteCounsellorDTO;
use App\DTOs\GetCounsellorStatsForAdminDTO;
use App\DTOs\GetModelsForAdminDTO;
use App\DTOs\UpdateCounsellorDTO;
use App\DTOs\VerifyCounsellorDTO;
use App\Enums\ConstantsEnum;
use App\Enums\PaginationEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorResource;
use App\Http\Resources\AdminCounsellorStatsResource;
use App\Http\Resources\OwnCounsellorRecognitionResource;
use App\Models\Counsellor;
use App\Models\LicensingAuthority;
use App\Models\User;
use App\Notifications\VerifyCounsellorEmailNotification;
use App\Support\PreferenceMatch;
use App\Support\PreferenceRanking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CounsellorService extends Service
{
    public const LEADING_LIMIT = 5;

    // How many ranked counsellors Leading considers (some may be hidden by a report), and how long the ranking is cached.
    public const LEADING_CANDIDATES = 50;

    public const LEADING_CACHE_SECONDS = 300;

    public const LEADING_CACHE_KEY = 'home.leading.';

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

        $updateCounsellorDTO->counsellor->notify(new VerifyCounsellorEmailNotification);
    }

    // SCRUM-392: $requestableOnly -- the "find a counsellor" picker lists only counsellors who are
    // currently verified (they'd be refused when the request is sent anyway); the public directory
    // still lists everyone not suspended, with their verification shown.
    public function getCounsellors(?User $user, ?string $name, bool $requestableOnly = false)
    {
        $query = Counsellor::query();
        $query->when($requestableOnly, fn ($query) => $query->whereCurrentlyVerified());

        // whereName() groups its own ORs, so they can't escape the filters below.
        $query->when($name, fn ($query) => $query->whereName($name));

        // A signed-out visitor can search too (this used to fail with a null user).
        $query->when($user, fn ($q) => $q->whereNotUser($user));
        // SCRUM-359: a suspended counsellor isn't offered to anyone.
        $query->whereNotSuspended();
        $query->withCount('stars');
        $query->orderBy('stars_count');
        // CounsellorMiniResource reads ->avatar; see GetOrganizationCounsellorsAction's comment
        // for why this MorphToMany needs an explicit eager load, unlike the old FK belongsTo.
        $query->with('avatarFile');

        return $query->paginate(PaginationEnum::pagination->value);
    }

    public function getCounsellorData(Counsellor $counsellor): array
    {
        $data = [
            'loadedCases' => TherapyCaseService::new()->getCases(),
            'loadedLanguages' => LanguageService::new()->getLanguages(),
            'loadedReligions' => ReligionService::new()->getReligions(),
            'loadedProfessions' => ProfessionService::new()->getProfessions(),
            // TT-7.6d/SCRUM-228: only ever merged into the page's props for the counsellor's own
            // profile view (see CounsellorController::show()'s own gate) -- private financial
            // data, never exposed on another viewer's request for this same page.
            'payoutOverview' => GetCounsellorPayoutOverviewAction::new()->execute($counsellor),
            // TT-7.11f/SCRUM-476: every approved recognition of theirs, whatever its consent and whether or not it is
            // shown right now, for the consent controls. Nothing says why one isn't shown.
            'recognitions' => OwnCounsellorRecognitionResource::collection($counsellor->recognitions()
                ->approved()
                ->with(['consentRequests' => fn ($query) => $query->wherePending()->where('type', RequestTypeEnum::counsellorRecognition->value)->latest('id')])
                ->orderByDesc('month')
                ->get())->resolve(),
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

        // SCRUM-363: not their own profile while suspended (an admin deleting it is unaffected).
        if ($deleteCounsellorDTO->counsellor->user?->is($deleteCounsellorDTO->user)) {
            EnsureCanDeleteOwnAccountWhileSuspendedAction::new()->execute($deleteCounsellorDTO->user);
        }

        return DeleteCounsellorAction::new()->execute($deleteCounsellorDTO);
    }

    public function verifyCounsellor(VerifyCounsellorDTO $verifyCounsellorDTO)
    {
        EnsureCounsellorExistsAction::new()->execute($verifyCounsellorDTO);

        EnsureCounsellorDoesNotHavePendingVerificationRequestAction::new()->execute($verifyCounsellorDTO);

        EnsureCanUpdateCounsellorAction::new()->execute($verifyCounsellorDTO);

        // SCRUM-31: when they may ask (not a year early) -- after the check that it's theirs to ask.
        EnsureCounsellorCanRequestVerificationAction::new()->execute($verifyCounsellorDTO);

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
                    ->where('name', ConstantsEnum::nationalId->value)->first(),
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
                'licensingAuthority' => LicensingAuthority::find($verifyCounsellorDTO->licensingAuthorityId),
            ])
        );

        $verifyCounsellorDTO = $verifyCounsellorDTO->withOtherLicense($otherLicense);
        $verifyCounsellorDTO = $verifyCounsellorDTO->withNationalIdLicense($nationalIdLicense);

        return CreateCounsellorVerificationRequestAction::new()->execute($verifyCounsellorDTO);
    }

    public function geCounsellorsForAdmin(GetModelsForAdminDTO $getModelsForAdminDTO)
    {
        if (is_null($getModelsForAdminDTO->user) || $getModelsForAdminDTO->user?->isNotAdmin()) {
            return [];
        }

        $query = Counsellor::query();

        if ($getModelsForAdminDTO->filterType == 'name') {
            $query->whereName((string) $getModelsForAdminDTO->filterValue);
        }

        // SCRUM-390: the counsellor's name, or their account's names and username.
        $query->when($getModelsForAdminDTO->search, fn ($query, $term) => $query->whereName($term));

        return AdminCounsellorResource::collection($query->with(['user', 'avatarFile', 'profession'])->paginate(
            PaginationEnum::preferencesPagination->value
        )->withQueryString());
    }

    public function getCounsellorStats(GetCounsellorStatsForAdminDTO $getCounsellorStatsForAdminDTO)
    {
        if (is_null($getCounsellorStatsForAdminDTO->user) || $getCounsellorStatsForAdminDTO->user?->isNotAdmin()) {
            return [];
        }

        return new AdminCounsellorStatsResource($getCounsellorStatsForAdminDTO->counsellor);
    }

    // TT-7.11f/SCRUM-476: Home's "Leading (current month)", step 1 -- who could lead: counsellors ranked on the new-rule
    // stars they earned this UTC month (CountCounsellorStarsAction; legacy stars never count; most stars, then the
    // earliest first star, then the lowest id -- GetRecognitionSuggestionsAction::rank()), the first LEADING_CANDIDATES.
    // The ranking is cached for LEADING_CACHE_SECONDS (security M2: it groups the whole month's stars); who is
    // recognisable now (verified, not suspended, not deleted) is checked live on every request. Open reports are left
    // to step 2, so Home reads them once for the showcase and Leading together (GetHomeCounsellorsAction).
    /** @return array<int, int> */
    public function getLeadingCandidateIds(): array
    {
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        $ranked = Cache::remember(self::LEADING_CACHE_KEY.$month->format('Y-m'), self::LEADING_CACHE_SECONDS, fn () => GetRecognitionSuggestionsAction::rank(
            CountCounsellorStarsAction::new()->execute(null, $month, $month->addMonth())
        )->keys()->take(self::LEADING_CANDIDATES)->all());

        if ($ranked === []) {
            return [];
        }

        $recognisable = Counsellor::query()->whereIn('id', $ranked)->whereRecognisableNow()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_intersect($ranked, $recognisable));
    }

    // Step 2: the first LEADING_LIMIT of $candidateIds (in order) not in $hiddenIds
    // (GetVisibleRecognitionsAction::publiclyHiddenIds() -- the same reports that hide a recognition), as cards. Their
    // counts are never sent, and only signed-in users get the list (HomeController).
    public function getLeadingCounsellors(array $candidateIds, array $hiddenIds): Collection
    {
        $ids = array_slice(array_values(array_diff($candidateIds, $hiddenIds)), 0, self::LEADING_LIMIT);

        if ($ids === []) {
            return collect();
        }

        // CounsellorCardResource reads ->avatar and ->cover (MorphToMany relations: eager-loaded, see
        // GetOrganizationCounsellorsAction), and getName() may fall back to the user's name.
        $counsellors = Counsellor::query()
            ->with(['user', 'avatarFile', 'coverFile'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn (int $id) => $counsellors->get($id))->filter()->values();
    }

    // SCRUM-113: ranked by the viewer's saved preferences, in a seeded order for the visit.
    public function getRandomCounsellors(?User $user, mixed $seed = null)
    {
        $seed = PreferenceRanking::seed($seed);

        $query = Counsellor::query();

        $query->when($user, function ($query) use ($user) {
            $query->whereNot('user_id', $user->id);
        });

        $query->whereNotSuspended();
        // CounsellorCardResource reads ->avatar and ->cover. TT-7.11f/SCRUM-476: no star rows any more -- it sends no
        // counts, so every star of every counsellor on the page is no longer loaded.
        $query->with(['avatarFile', 'coverFile']);

        PreferenceRanking::apply($query, PreferenceMatch::forUser($user), $seed);

        return $query->paginate(PaginationEnum::preferencesPagination->value)->appends(['seed' => $seed]);
    }
}
