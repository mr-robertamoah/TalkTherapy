<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToCounsellorVerificationRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\ConstantsEnum;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\InvalidVerificationRequestData;
use App\Http\Resources\LicenseResource;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Request;
use App\Models\User;
use App\Services\AppService;
use App\Services\LicenseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// SCRUM-338: rejecting a counsellor verification request no longer validates its licenses, and
// verification documents are deleted under the retention policy (records kept, files removed).

function retentionAuthority(string $name, bool $validated = false): LicensingAuthority
{
    $authority = LicensingAuthority::factory()->create([
        'name' => $name,
        'type' => LicensingAuthorityTypeEnum::govermental->value,
        'license_type' => LicensingTypeEnum::number->value,
    ]);
    $authority->forceFill(['validated' => $validated])->save();

    return $authority;
}

function retentionLicense(Counsellor $counsellor, LicensingAuthority $authority, string $number): License
{
    $license = new License(['number' => $number]);
    $license->licensingAuthority()->associate($authority);
    $license->for()->associate($counsellor);
    $license->save();

    Storage::disk('license_documents')->putFileAs('licenses', UploadedFile::fake()->image("{$number}.jpg"), "{$number}.jpg");
    $license->files()->attach(File::factory()->create([
        'name' => "{$number}.jpg",
        'path' => 'licenses',
        'storage' => 'license_documents',
        'mime' => 'image/jpeg',
    ])->id);

    return $license;
}

// [counsellor, request, nationalIdLicense, otherLicense]
function retentionVerificationRequest(?Counsellor $counsellor = null, ?LicensingAuthority $otherAuthority = null): array
{
    $counsellor ??= Counsellor::factory()->create(['user_id' => User::factory()->create()->id, 'verified_at' => null]);
    $nationalId = retentionLicense($counsellor, retentionAuthority(ConstantsEnum::nationalId->value, true), 'NID-'.fake()->unique()->numerify('#####'));
    $other = retentionLicense($counsellor, $otherAuthority ?? retentionAuthority('Counselling Board'), 'PRO-'.fake()->unique()->numerify('#####'));

    $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $counsellor,
        'to' => User::factory()->create(),
        'for' => $counsellor,
        'type' => RequestTypeEnum::counsellor->value,
        'data' => ['nationalIdLicense' => $nationalId->id, 'otherLicense' => $other->id],
    ]));

    return [$counsellor, $request, $nationalId, $other];
}

function retentionRespond(Request $request, ?string $response): Request
{
    return RespondToCounsellorVerificationRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'response' => $response,
        'request' => $request,
    ]));
}

function retentionDecidedDaysAgo(Request $request, string $status, int $days): void
{
    Request::query()->whereKey($request->id)->update(['status' => $status, 'updated_at' => now()->subDays($days)]);
}

beforeEach(function () {
    Storage::fake('license_documents');
});

test('rejecting a verification request leaves its licenses and licensing authority unvalidated', function () {
    [$counsellor, $request, $nationalId, $other] = retentionVerificationRequest();

    expect(retentionRespond($request, 'rejected')->status)->toBe(RequestStatusEnum::rejected->value);

    expect((bool) $nationalId->fresh()->validated)->toBeFalse()
        ->and((bool) $other->fresh()->validated)->toBeFalse()
        ->and((bool) $other->licensingAuthority->fresh()->validated)->toBeFalse()
        ->and($counsellor->fresh()->hasNationalIdentification())->toBeFalse()
        ->and($counsellor->fresh()->verified_at)->toBeNull();
});

test('a request with no response counts as rejected and validates nothing', function () {
    [, $request, $nationalId, $other] = retentionVerificationRequest();

    expect(retentionRespond($request, null)->status)->toBe(RequestStatusEnum::rejected->value);
    expect((bool) $nationalId->fresh()->validated)->toBeFalse()
        ->and((bool) $other->fresh()->validated)->toBeFalse();
});

test('accepting a verification request still validates its licenses and authority', function () {
    [$counsellor, $request, $nationalId, $other] = retentionVerificationRequest();

    retentionRespond($request, 'accepted');

    expect((bool) $nationalId->fresh()->validated)->toBeTrue()
        ->and((bool) $other->fresh()->validated)->toBeTrue()
        ->and((bool) $other->licensingAuthority->fresh()->validated)->toBeTrue()
        ->and($counsellor->fresh()->hasNationalIdentification())->toBeTrue();
});

test('the repair command un-validates licenses that only appear on rejected requests', function () {
    [, $rejected, $badNationalId, $badOther] = retentionVerificationRequest();
    retentionDecidedDaysAgo($rejected, RequestStatusEnum::rejected->value, 1);
    $badNationalId->validate();
    $badOther->validate();

    [, $accepted, $goodNationalId] = retentionVerificationRequest();
    retentionRespond($accepted, 'accepted');

    $this->artisan('licenses:repair-rejected-validations --dry-run')->assertSuccessful();
    expect((bool) $badNationalId->fresh()->validated)->toBeTrue();

    $this->artisan('licenses:repair-rejected-validations')->assertSuccessful();

    expect((bool) $badNationalId->fresh()->validated)->toBeFalse()
        ->and((bool) $badOther->fresh()->validated)->toBeFalse()
        ->and((bool) $goodNationalId->fresh()->validated)->toBeTrue();
});

test('the repair leaves alone a license that was later part of an accepted request', function () {
    [$counsellor, $rejected, $nationalId, $other] = retentionVerificationRequest();
    retentionDecidedDaysAgo($rejected, RequestStatusEnum::rejected->value, 5);

    $resubmitted = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $counsellor,
        'to' => User::factory()->create(),
        'for' => $counsellor,
        'type' => RequestTypeEnum::counsellor->value,
        'data' => ['nationalIdLicense' => $nationalId->id, 'otherLicense' => $other->id],
    ]));
    retentionRespond($resubmitted, 'accepted');

    $this->artisan('licenses:repair-rejected-validations')->assertSuccessful();

    expect((bool) $nationalId->fresh()->validated)->toBeTrue();
});

test('accepting a request whose licenses are gone is refused rather than accepted with nothing validated', function () {
    [$counsellor, $request, $nationalId] = retentionVerificationRequest();
    $nationalId->delete();

    expect(fn () => retentionRespond($request, 'accepted'))->toThrow(InvalidVerificationRequestData::class);
    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value)
        ->and($counsellor->fresh()->verified_at)->toBeNull();
});

test('a rejected request\'s documents are deleted after the retention window, and the records kept', function () {
    [, $request, $nationalId, $other] = retentionVerificationRequest();
    retentionDecidedDaysAgo($request, RequestStatusEnum::rejected->value, 31);
    $paths = [$nationalId->files->first()->diskPath(), $other->files->first()->diskPath()];

    LicenseService::new()->applyDocumentRetention();

    foreach ($paths as $path) {
        Storage::disk('license_documents')->assertMissing($path);
    }
    expect($nationalId->fresh()->files)->toBeEmpty()
        ->and($nationalId->fresh()->documents_removed_at)->not->toBeNull()
        ->and($nationalId->fresh()->number)->toBeNull()
        ->and($other->fresh()->files)->toBeEmpty()
        ->and($other->fresh()->number)->not->toBeNull()
        ->and(License::query()->whereKey([$nationalId->id, $other->id])->count())->toBe(2);
});

test('a rejected request\'s documents are kept inside the retention window', function () {
    [, $request, $nationalId] = retentionVerificationRequest();
    retentionDecidedDaysAgo($request, RequestStatusEnum::rejected->value, 29);

    LicenseService::new()->applyDocumentRetention();

    expect($nationalId->fresh()->files)->toHaveCount(1)
        ->and($nationalId->fresh()->documents_removed_at)->toBeNull();
});

test('an accepted request loses only its national ID scan and number; the professional license stays', function () {
    [$counsellor, $request, $nationalId, $other] = retentionVerificationRequest();
    retentionRespond($request, 'accepted');
    retentionDecidedDaysAgo($request, RequestStatusEnum::accepted->value, 31);

    LicenseService::new()->applyDocumentRetention();

    expect($nationalId->fresh()->files)->toBeEmpty()
        ->and($nationalId->fresh()->number)->toBeNull()
        ->and((bool) $nationalId->fresh()->validated)->toBeTrue()
        ->and($counsellor->fresh()->hasNationalIdentification())->toBeTrue()
        ->and($other->fresh()->files)->toHaveCount(1)
        ->and($other->fresh()->documents_removed_at)->toBeNull();
});

test('a license cited by a pending request is never swept, whatever its age', function () {
    [$counsellor, $request, $nationalId] = retentionVerificationRequest();
    retentionDecidedDaysAgo($request, RequestStatusEnum::rejected->value, 90);

    // A resubmission citing the same license, not yet reviewed.
    CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $counsellor,
        'to' => User::factory()->create(),
        'for' => $counsellor,
        'type' => RequestTypeEnum::counsellor->value,
        'data' => ['nationalIdLicense' => $nationalId->id, 'otherLicense' => null],
    ]));

    LicenseService::new()->applyDocumentRetention();

    expect($nationalId->fresh()->files)->toHaveCount(1);
});

test('the backfill command dry run changes nothing and the real run applies the policy', function () {
    [, $request, $nationalId] = retentionVerificationRequest();
    retentionDecidedDaysAgo($request, RequestStatusEnum::rejected->value, 45);

    $this->artisan('licenses:apply-retention --dry-run')->assertSuccessful();
    expect($nationalId->fresh()->files)->toHaveCount(1);

    $this->artisan('licenses:apply-retention')->assertSuccessful();
    expect($nationalId->fresh()->files)->toBeEmpty();

    // Idempotent: nothing left to do on a second run.
    expect(LicenseService::new()->applyDocumentRetention(dryRun: true))->toBeEmpty();
});

test('purging a soft-deleted counsellor removes their license documents but keeps the records', function () {
    [$counsellor, , $nationalId, $other] = retentionVerificationRequest();
    $counsellor->delete();
    Counsellor::withTrashed()->whereKey($counsellor->id)->update(['deleted_at' => now()->subDays(61)]);

    AppService::new()->purgeExpiredSoftDeletedCounsellors();

    expect(Counsellor::withTrashed()->find($counsellor->id))->toBeNull()
        ->and($nationalId->fresh()->files)->toBeEmpty()
        ->and($nationalId->fresh()->number)->toBeNull()
        ->and($other->fresh()->files)->toBeEmpty()
        ->and($other->fresh()->documents_removed_at)->not->toBeNull();
});

test('the license resource says when its documents were removed under the retention policy', function () {
    [, $request, $nationalId] = retentionVerificationRequest();
    retentionDecidedDaysAgo($request, RequestStatusEnum::rejected->value, 31);
    LicenseService::new()->applyDocumentRetention();

    $data = (new LicenseResource($nationalId->fresh()))->resolve();

    expect($data['documentsRemovedAt'])->not->toBeNull()
        ->and($data['file'])->toBeEmpty();
});
